<?php
class Archivematica_Form_Import extends Omeka_Form
{
    public function init()
    {
        parent::init();
        $this->setAttrib('enctype', 'multipart/form-data');

        // Zend can't render a file input with the default ViewHelper decorator
        $fileDecorators = $this->getDefaultElementDecorators();
        $fileDecorators[array_search('ViewHelper', $fileDecorators, true)] = 'File';
        $this->addElement('file', 'dip_file', [
            'label' => __('DIP File'),
            'description' => __('A DIP .tar file exported from Archivematica. The maximum file size is %s.', max_file_size()),
            'required' => true,
            'valueDisabled' => true,
            'validators' => [['Extension', false, 'tar']],
            'decorators' => $fileDecorators,
        ]);
        $this->addElement('select', 'collection_id', [
            'label' => __('Collection'),
            'description' => __('Add new items to this collection.'),
            'multiOptions' => get_table_options('Collection'),
        ]);
        $this->addElement('checkbox', 'public', [
            'label' => __('Public'),
            'description' => __('Make new items public.'),
            'value' => true,
            'class' => 'checkbox',
        ]);
        $this->addElement('textarea', 'comment', [
            'label' => __('Comment'),
            'rows' => 4,
        ]);
    }
}
