<?php
class ArchivematicaPlugin extends Omeka_Plugin_AbstractPlugin
{
    protected $_hooks = [
        'install',
        'uninstall',
        'initialize',
        'define_acl',
        'config_form',
        'config',
        'before_delete_item',
        'items_browse_sql',
    ];

    protected $_filters = [
        'admin_navigation_main',
        'item_search_filters',
    ];

    public function hookInstall()
    {
        $db = $this->_db;
        $sql = "
        CREATE TABLE IF NOT EXISTS `$db->ArchivematicaSip` (
            `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
            `source_type` varchar(255) COLLATE utf8_unicode_ci NOT NULL,
            `source_id` int(10) unsigned NOT NULL,
            `owner_id` int(10) unsigned NOT NULL,
            `added` timestamp NOT NULL,
            `status` varchar(255) COLLATE utf8_unicode_ci DEFAULT NULL,
            `sip_name` varchar(255) COLLATE utf8_unicode_ci DEFAULT NULL,
            `error_message` text COLLATE utf8_unicode_ci,
            `data` text COLLATE utf8_unicode_ci,
            PRIMARY KEY (`id`),
            KEY `source` (`source_type`, `source_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci";
        $db->query($sql);

        $sql = "
        CREATE TABLE IF NOT EXISTS `$db->ArchivematicaImport` (
            `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
            `owner_id` int(10) unsigned NOT NULL,
            `added` timestamp NOT NULL,
            `status` varchar(255) COLLATE utf8_unicode_ci DEFAULT NULL,
            `original_filename` varchar(255) COLLATE utf8_unicode_ci DEFAULT NULL,
            `comment` text COLLATE utf8_unicode_ci,
            `collection_id` int(10) unsigned DEFAULT NULL,
            `public` tinyint(1) unsigned NOT NULL DEFAULT 1,
            `added_count` int(10) unsigned NOT NULL DEFAULT 0,
            `updated_count` int(10) unsigned NOT NULL DEFAULT 0,
            `error_message` text COLLATE utf8_unicode_ci,
            `skipped_files` text COLLATE utf8_unicode_ci,
            PRIMARY KEY (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci";
        $db->query($sql);

        $sql = "
        CREATE TABLE IF NOT EXISTS `$db->ArchivematicaItem` (
            `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
            `item_id` int(10) unsigned NOT NULL,
            `import_id` int(10) unsigned NOT NULL,
            `uri` varchar(255) COLLATE utf8_unicode_ci DEFAULT NULL,
            `last_modified` timestamp NOT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `item_id` (`item_id`),
            KEY `import_id` (`import_id`),
            KEY `uri` (`uri`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci";
        $db->query($sql);
    }

    public function hookUninstall()
    {
        $db = $this->_db;
        $db->query("DROP TABLE IF EXISTS `$db->ArchivematicaSip`");
        $db->query("DROP TABLE IF EXISTS `$db->ArchivematicaImport`");
        $db->query("DROP TABLE IF EXISTS `$db->ArchivematicaItem`");
        delete_option('archivematica_sips_directory_path');
    }

    public function hookInitialize()
    {
        add_translation_source(dirname(__FILE__) . '/languages');
    }

    public function hookDefineAcl($args)
    {
        $acl = $args['acl'];
        $acl->addResource('Archivematica_Index');
        $acl->addResource('Archivematica_Sip');
        $acl->addResource('Archivematica_Import');
    }

    public function hookConfigForm()
    {
        echo get_view()->partial('archivematica-config-form.php');
    }

    public function hookConfig()
    {
        $path = realpath($_POST['sips_directory_path']);
        if (!$path || !is_dir($path) || !is_writable($path)) {
            throw new Omeka_Plugin_Installer_Exception('Invalid SIPs directory path');
        }
        set_option('archivematica_sips_directory_path', $path);
    }

    public function hookBeforeDeleteItem($args)
    {
        $archivematicaItem = $this->_db->getTable('ArchivematicaItem')
            ->findBySql('item_id = ?', [$args['record']->id], true);
        if ($archivematicaItem) {
            $archivematicaItem->delete();
        }
    }

    public function hookItemsBrowseSql($args)
    {
        if (!empty($args['params']['archivematica_import_id'])) {
            $args['select']
                ->join(['archivematica_items' => $this->_db->ArchivematicaItem], 'archivematica_items.item_id = items.id', [])
                ->where('archivematica_items.import_id = ?', (int) $args['params']['archivematica_import_id']);
        }
    }

    public function filterAdminNavigationMain($nav)
    {
        $nav[] = [
            'label' => __('Archivematica'),
            'uri' => url('archivematica'),
            'resource' => 'Archivematica_Index',
        ];
        return $nav;
    }

    public function filterItemSearchFilters($displayArray, $args)
    {
        if (!empty($args['request_array']['archivematica_import_id'])) {
            $displayArray['Archivematica Import'] = '#' . (int) $args['request_array']['archivematica_import_id'];
        }
        return $displayArray;
    }
}
