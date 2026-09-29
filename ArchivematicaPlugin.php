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
    ];

    protected $_filters = [
        'admin_navigation_main',
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
            PRIMARY KEY (`id`),
            KEY `source` (`source_type`, `source_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci";
        $db->query($sql);
    }

    public function hookUninstall()
    {
        $db = $this->_db;
        $sql = "DROP TABLE IF EXISTS `$db->ArchivematicaSip`";
        $db->query($sql);
        delete_option('archivematica_sips_directory_path');
        delete_option('archivematica_include_items_without_files');
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
        set_option('archivematica_include_items_without_files', $_POST['include_items_without_files']);
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
}
