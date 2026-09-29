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
