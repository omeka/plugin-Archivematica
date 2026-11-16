<?php echo head(['title' => __('Import DIP'), 'bodyclass' => 'archivematica add']); ?>
<?php echo $this->partial('archivematica-navigation.php'); ?>
<?php echo flash(); ?>

<form method="post" enctype="multipart/form-data">
<section class="seven columns alpha">
    <?php echo $form->getElement('dip_file'); ?>
    <?php echo $form->getElement('collection_id'); ?>
    <?php echo $form->getElement('public'); ?>
    <?php echo $form->getElement('comment'); ?>
    <?php echo $csrf; ?>
</section>
<section class="three columns omega">
    <div id="save" class="panel">
        <input type="submit" class="submit full-width green button" value="<?php echo __('Import'); ?>">
    </div>
</section>
</form>

<?php echo foot(); ?>
