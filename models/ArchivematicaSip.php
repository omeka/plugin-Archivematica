<?php
class ArchivematicaSip extends Omeka_Record_AbstractRecord implements Zend_Acl_Resource_Interface
{
    public $source_type;
    public $source_id;
    public $owner_id;
    public $added;
    public $status;
    public $sip_name;
    public $error_message;
    public $data;

    protected function _initializeMixins()
    {
        $this->_mixins[] = new Mixin_Owner($this);
        $this->_mixins[] = new Mixin_Timestamp($this);
    }

    public function setStatus($status)
    {
        $this->status = $status;
    }

    public function getStatus()
    {
        return $this->status;
    }

    public function setData(array $data)
    {
        $this->data = json_encode($data);
    }

    public function getData()
    {
        return json_decode($this->data, true) ?: [];
    }

    public function getResourceId()
    {
        return 'Archivematica_Sip';
    }

    protected function afterDelete()
    {
        $sipsDirectoryPath = get_option('archivematica_sips_directory_path');
        if (!$this->sip_name || !$sipsDirectoryPath) {
            return;
        }
        $sipZipPath = sprintf('%s/%s.zip', $sipsDirectoryPath, $this->sip_name);
        if (is_file($sipZipPath)) {
            unlink($sipZipPath);
        }
    }
}
