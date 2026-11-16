<nav id="section-nav" class="navigation vertical" aria-label="<?php echo __('Archivematica'); ?>">
<?php
    $navArray = [
        [
            'label' => __('SIP Exports'),
            'uri' => url('archivematica'),
            'resource' => 'Archivematica_Index',
        ],
        [
            'label' => __('DIP Imports'),
            'uri' => url('archivematica/import'),
            'resource' => 'Archivematica_Import',
        ],
    ];
    echo nav($navArray, 'admin_navigation_archivematica');
?>
</nav>
