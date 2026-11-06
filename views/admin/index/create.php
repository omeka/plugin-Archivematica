<?php echo head(['title' => __('Create SIP')]); ?>
<?php echo flash(); ?>

<p><?php echo __('Choose what to package as an Archivematica SIP.'); ?></p>

<ul class="action-links group">
    <li>
        <a href="<?php echo html_escape(url(['controller' => 'index', 'action' => 'create-static-site'])); ?>" class="button"><?php echo __('Static Site Export'); ?></a>
    </li>
    <li>
        <a href="<?php echo html_escape(url(['controller' => 'index', 'action' => 'create-items'])); ?>" class="button"><?php echo __('Item(s)'); ?></a>
    </li>
</ul>

<?php echo foot(); ?>
