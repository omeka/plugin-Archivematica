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

    public function getResourceId()
    {
        return 'Archivematica_Sip';
    }
}
