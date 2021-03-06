<?php

/**
 * Type-aware folder management/listing for Kolab
 *
 * @version @package_version@
 * @author Aleksander Machniak <machniak@kolabsys.com>
 *
 * Copyright (C) 2011, Kolab Systems AG <contact@kolabsys.com>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program. If not, see <http://www.gnu.org/licenses/>.
 */

class kolab_folders extends rcube_plugin
{
    public $task = '?(?!login).*';

    public $types = array('mail', 'event', 'journal', 'task', 'note', 'contact', 'configuration', 'file', 'freebusy');
    public $mail_types = array('inbox', 'drafts', 'sentitems', 'outbox', 'wastebasket', 'junkemail');

    private $rc;
    private static $instance;


    /**
     * Plugin initialization.
     */
    function init()
    {
        self::$instance = $this;
        $this->rc = rcube::get_instance();

        // load required plugin
        $this->require_plugin('libkolab');

        // Folder listing hooks
        $this->add_hook('storage_folders', array($this, 'mailboxes_list'));

        // Folder manager hooks
        $this->add_hook('folder_form', array($this, 'folder_form'));
        $this->add_hook('folder_update', array($this, 'folder_save'));
        $this->add_hook('folder_create', array($this, 'folder_save'));
        $this->add_hook('folder_delete', array($this, 'folder_save'));
        $this->add_hook('folder_rename', array($this, 'folder_save'));
        $this->add_hook('folders_list', array($this, 'folders_list'));

        // Special folders setting
        $this->add_hook('preferences_save', array($this, 'prefs_save'));
    }

    /**
     * Handler for mailboxes_list hook. Enables type-aware lists filtering.
     */
    function mailboxes_list($args)
    {
        // infinite loop prevention
        if ($this->is_processing) {
            return $args;
        }

        if (!$this->metadata_support()) {
            return $args;
        }

        $this->is_processing = true;

        // get folders
        $folders = kolab_storage::list_folders($args['root'], $args['name'], $args['filter'], $args['mode'] == 'LSUB', $folderdata);

        $this->is_processing = false;

        if (!is_array($folders)) {
            return $args;
        }

        // Create default folders
        if ($args['root'] == '' && $args['name'] = '*') {
            $this->create_default_folders($folders, $args['filter'], $folderdata);
        }

        $args['folders'] = $folders;

        return $args;
    }

    /**
     * Handler for folders_list hook. Add css classes to folder rows.
     */
    function folders_list($args)
    {
        if (!$this->metadata_support()) {
            return $args;
        }

        // get folders types
        $folderdata = kolab_storage::folders_typedata();

        if (!is_array($folderdata)) {
            return $args;
        }

        $table = $args['table'];

        // Add type-based style for table rows
        // See kolab_folders::folder_class_name()
        for ($i=1, $cnt=$table->size(); $i<=$cnt; $i++) {
            $attrib = $table->get_row_attribs($i);
            $folder = $attrib['foldername']; // UTF7-IMAP
            $type   = $folderdata[$folder];

            if (!$type) {
                $type = 'mail';
            }

            $class_name = self::folder_class_name($type);

            $attrib['class'] = trim($attrib['class'] . ' ' . $class_name);
            $table->set_row_attribs($attrib, $i);
        }

        return $args;
    }

    /**
     * Handler for folder info/edit form (folder_form hook).
     * Adds folder type selector.
     */
    function folder_form($args)
    {
        if (!$this->metadata_support()) {
            return $args;
        }
        // load translations
        $this->add_texts('localization/', false);

        // INBOX folder is of type mail.inbox and this cannot be changed
        if ($args['name'] == 'INBOX') {
            $args['form']['props']['fieldsets']['settings']['content']['foldertype'] = array(
                'label' => $this->gettext('folderctype'),
                'value' => sprintf('%s (%s)', $this->gettext('foldertypemail'), $this->gettext('inbox')),
            );

            return $args;
        }

        if ($args['options']['is_root']) {
            return $args;
        }

        $mbox = strlen($args['name']) ? $args['name'] : $args['parent_name'];

        if (isset($_POST['_ctype'])) {
            $new_ctype   = trim(get_input_value('_ctype', RCUBE_INPUT_POST));
            $new_subtype = trim(get_input_value('_subtype', RCUBE_INPUT_POST));
        }

        // Get type of the folder or the parent
        if (strlen($mbox)) {
            list($ctype, $subtype) = $this->get_folder_type($mbox);
            if (strlen($args['parent_name']) && $subtype == 'default')
                $subtype = ''; // there can be only one
        }

        if (!$ctype) {
            $ctype = 'mail';
        }

        $storage = $this->rc->get_storage();

        // Don't allow changing type of shared folder, according to ACL
        if (strlen($mbox)) {
            $options = $storage->folder_info($mbox);
            if ($options['namespace'] != 'personal' && !in_array('a', (array)$options['rights'])) {
                if (in_array($ctype, $this->types)) {
                    $value = $this->gettext('foldertype'.$ctype);
                }
                else {
                    $value = $ctype;
                }
                if ($subtype) {
                    $value .= ' ('. ($subtype == 'default' ? $this->gettext('default') : $subtype) .')';
                }

                $args['form']['props']['fieldsets']['settings']['content']['foldertype'] = array(
                    'label' => $this->gettext('folderctype'),
                    'value' => $value,
                );

                return $args;
            }
        }

        // Add javascript script to the client
        $this->include_script('kolab_folders.js');

        // build type SELECT fields
        $type_select = new html_select(array('name' => '_ctype', 'id' => '_ctype'));
        $sub_select  = new html_select(array('name' => '_subtype', 'id' => '_subtype'));

        foreach ($this->types as $type) {
            $type_select->add($this->gettext('foldertype'.$type), $type);
        }
        // add non-supported type
        if (!in_array($ctype, $this->types)) {
            $type_select->add($ctype, $ctype);
        }

        $sub_select->add('', '');
        $sub_select->add($this->gettext('default'), 'default');
        foreach ($this->mail_types as $type) {
            $sub_select->add($this->gettext($type), $type);
        }

        $args['form']['props']['fieldsets']['settings']['content']['foldertype'] = array(
            'label' => $this->gettext('folderctype'),
            'value' => $type_select->show(isset($new_ctype) ? $new_ctype : $ctype)
                . $sub_select->show(isset($new_subtype) ? $new_subtype : $subtype),
        );

        return $args;
    }

    /**
     * Handler for folder update/create action (folder_update/folder_create hook).
     */
    function folder_save($args)
    {
        // Folder actions from folders list
        if (empty($args['record'])) {
            return $args;
        }

        // Folder create/update with form
        $ctype     = trim(get_input_value('_ctype', RCUBE_INPUT_POST));
        $subtype   = trim(get_input_value('_subtype', RCUBE_INPUT_POST));
        $mbox      = $args['record']['name'];
        $old_mbox  = $args['record']['oldname'];
        $subscribe = $args['record']['subscribe'];

        if (empty($ctype)) {
            return $args;
        }

        // load translations
        $this->add_texts('localization/', false);

        // Skip folder creation/rename in core
        // @TODO: Maybe we should provide folder_create_after and folder_update_after hooks?
        //        Using create_mailbox/rename_mailbox here looks bad
        $args['abort']  = true;

        // There can be only one default folder of specified type
        if ($subtype == 'default') {
            $default = $this->get_default_folder($ctype);

            if ($default !== null && $old_mbox != $default) {
                $args['result'] = false;
                $args['message'] = $this->gettext('defaultfolderexists');
                return $args;
            }
        }
        // Subtype sanity-checks
        else if ($subtype && ($ctype != 'mail' || !in_array($subtype, $this->mail_types))) {
            $subtype = '';
        }

        $ctype .= $subtype ? '.'.$subtype : '';

        $storage = $this->rc->get_storage();

        // Create folder
        if (!strlen($old_mbox)) {
            // By default don't subscribe to non-mail folders
            if ($subscribe)
                $subscribe = (bool) preg_match('/^mail/', $ctype);

            $result = $storage->create_folder($mbox, $subscribe);
            // Set folder type
            if ($result) {
                $this->set_folder_type($mbox, $ctype);
            }
        }
        // Rename folder
        else {
            if ($old_mbox != $mbox) {
                $result = $storage->rename_folder($old_mbox, $mbox);
            }
            else {
                $result = true;
            }

            if ($result) {
                list($oldtype, $oldsubtype) = $this->get_folder_type($mbox);
                $oldtype .= $oldsubtype ? '.'.$oldsubtype : '';

                if ($ctype != $oldtype) {
                    $this->set_folder_type($mbox, $ctype);
                }
            }
        }

        $args['record']['class'] = self::folder_class_name($ctype);
        $args['record']['subscribe'] = $subscribe;
        $args['result'] = $result;

        return $args;
    }

    /**
     * Handler for user preferences save (preferences_save hook)
     *
     * @param array $args Hash array with hook parameters
     *
     * @return array Hash array with modified hook parameters
     */
    public function prefs_save($args)
    {
        if ($args['section'] != 'folders') {
            return $args;
        }

        // Load configuration
        $this->load_config();

        // Check that configuration is not disabled
        $dont_override  = (array) $this->rc->config->get('dont_override', array());

        // special handling for 'default_folders'
        if (in_array('default_folders', $dont_override)) {
            return $args;
        }

        // map config option name to kolab folder type annotation
        $opts = array(
            'drafts_mbox' => 'mail.drafts',
            'sent_mbox'   => 'mail.sentitems',
            'junk_mbox'   => 'mail.junkemail',
            'trash_mbox'  => 'mail.wastebasket',
        );

        // check if any of special folders has been changed
        foreach ($opts as $opt_name => $type) {
            $new = $args['prefs'][$opt_name];
            $old = $this->rc->config->get($opt_name);
            if ($new === $old) {
                unset($opts[$opt_name]);
            }
        }

        if (empty($opts)) {
            return $args;
        }

        $folderdata = kolab_storage::folders_typedata();

        if (!is_array($folderdata)) {
             return $args;
        }

        foreach ($opts as $opt_name => $type) {
            $foldername = $args['prefs'][$opt_name];
            if (strlen($foldername)) {

                // get all folders of specified type
                $folders = array_intersect($folderdata, array($type));

                // folder already annotated with specified type
                if (!empty($folders[$foldername])) {
                    continue;
                }

                // set type to the new folder
                $this->set_folder_type($foldername, $type);

                // unset old folder(s) type annotation
                list($maintype, $subtype) = explode('.', $type);
                foreach (array_keys($folders) as $folder) {
                    $this->set_folder_type($folder, $maintype);
                }
            }
        }

        return $args;
    }

    /**
     * Checks if IMAP server supports any of METADATA, ANNOTATEMORE, ANNOTATEMORE2
     *
     * @return boolean
     */
    function metadata_support()
    {
        $storage = $this->rc->get_storage();

        return $storage->get_capability('METADATA') ||
            $storage->get_capability('ANNOTATEMORE') ||
            $storage->get_capability('ANNOTATEMORE2');
    }

    /**
     * Checks if IMAP server supports any of METADATA, ANNOTATEMORE, ANNOTATEMORE2
     *
     * @param string $folder Folder name
     *
     * @return array Folder content-type
     */
    function get_folder_type($folder)
    {
        return explode('.', (string)kolab_storage::folder_type($folder));
    }

    /**
     * Sets folder content-type.
     *
     * @param string $folder Folder name
     * @param string $type   Content type
     *
     * @return boolean True on success
     */
    function set_folder_type($folder, $type = 'mail')
    {
        return kolab_storage::set_folder_type($folder, $type);
    }

    /**
     * Returns the name of default folder
     *
     * @param string $type Folder type
     *
     * @return string Folder name
     */
    function get_default_folder($type)
    {
        $folderdata = kolab_storage::folders_typedata();

        if (!is_array($folderdata)) {
            return null;
        }

        // get all folders of specified type
        $folderdata = array_intersect($folderdata, array($type.'.default'));

        return key($folderdata);
    }

    /**
     * Returns CSS class name for specified folder type
     *
     * @param string $type Folder type
     *
     * @return string Class name
     */
    static function folder_class_name($type)
    {
        list($ctype, $subtype) = explode('.', $type);

        $class[] = 'type-' . ($ctype ? $ctype : 'mail');

        if ($subtype)
            $class[] = 'subtype-' . $subtype;

        return implode(' ', $class);
    }

    /**
     * Creates default folders if they doesn't exist
     */
    private function create_default_folders(&$folders, $filter, $folderdata = null)
    {
        $storage     = $this->rc->get_storage();
        $namespace   = $storage->get_namespace();
        $defaults    = array();
        $prefix      = '';

        // Find personal namespace prefix
        if (is_array($namespace['personal']) && count($namespace['personal']) == 1) {
            $prefix = $namespace['personal'][0][0];
        }

        $this->load_config();

        // get configured defaults
        foreach ($this->types as $type) {
            $subtypes = $type == 'mail' ? $this->mail_types : array('default');
            foreach ($subtypes as $subtype) {
                $opt_name = 'kolab_folders_' . $type . '_' . $subtype;
                if ($folder = $this->rc->config->get($opt_name)) {
                    // convert configuration value to UTF7-IMAP charset
                    $folder = rcube_charset::convert($folder, RCUBE_CHARSET, 'UTF7-IMAP');
                    // and namespace prefix if needed
                    if ($prefix && strpos($folder, $prefix) === false && $folder != 'INBOX') {
                        $folder = $prefix . $folder;
                    }
                    $defaults[$type . '.' . $subtype] = $folder;
                }
            }
        }

        if (empty($defaults)) {
            return;
        }

        if ($folderdata === null) {
            $folderdata = kolab_storage::folders_typedata();
        }

        if (!is_array($folderdata)) {
            return;
        }

        // find default folders
        foreach ($defaults as $type => $foldername) {
            // get all folders of specified type
            $_folders = array_intersect($folderdata, array($type));

            // default folder found
            if (!empty($_folders)) {
                continue;
            }

            list($type1, $type2) = explode('.', $type);
            $exists = !empty($folderdata[$foldername]) || $foldername == 'INBOX';

            // create folder
            if (!$exists && !$storage->folder_exists($foldername)) {
                $storage->create_folder($foldername);
                $storage->subscribe($foldername);
            }

            // set type
            $result = $this->set_folder_type($foldername, $type);

            // add new folder to the result
            if ($result && (!$filter || $filter == $type1)) {
                $folders[] = $foldername;
            }
        }
    }


    /**
     * Static getter for default folder of the given type
     *
     * @param string $type Folder type
     * @return string Folder name
     */
    public static function default_folder($type)
    {
        return self::$instance->get_default_folder($type);
    }
}
