<div class="field">
    <div class="two columns alpha">
        <label for="sips_directory_path"><?php echo __('SIPs directory path'); ?></label>
    </div>
    <div class="inputs five columns omega">
        <p class="explanation"><?php echo __('Enter the path to the directory where packaged Archivematica Submission Information Packages (SIPs) will be saved. The path must exist and be writable by the web server.'); ?></p>
        <?php echo $this->formText(
            'sips_directory_path',
            get_option('archivematica_sips_directory_path')
        ); ?>
    </div>
</div>
<div class="field">
    <div class="two columns alpha">
        <label for="include_items_without_files"><?php echo __('Include items without files'); ?></label>
    </div>
    <div class="inputs five columns omega">
        <p class="explanation"><?php echo __('Archivematica requires a corresponding content file for every entry in metadata.csv. If checked, generate a JSON metadata snapshot file for Omeka items without file/s to point to. If unchecked, skip items without file/s.'); ?></p>
        <?php echo $this->formCheckbox(
            'include_items_without_files',
            true,
            ['checked' => (bool) get_option('archivematica_include_items_without_files')]
        ); ?>
    </div>
</div>
