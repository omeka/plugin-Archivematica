<?php
class ArchivematicaImport extends Omeka_Record_AbstractRecord implements Zend_Acl_Resource_Interface
{
    const STATUS_UNDO_IN_PROGRESS = 'undo in progress';
    const STATUS_UNDONE = 'undone';

    public $owner_id;
    public $added;
    public $status;
    public $original_filename;
    public $comment;
    public $collection_id;
    public $public = 1;
    public $added_count = 0;
    public $updated_count = 0;
    public $error_message;
    public $skipped_files;

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

    public function canUndo()
    {
        return in_array($this->status, [Process::STATUS_COMPLETED, Process::STATUS_ERROR]);
    }

    // Count of DIP files Omeka refused (disallowed type/extension), stored as JSON keyed by extension
    public function addSkippedFile($filename)
    {
        $counts = $this->getSkippedFileCounts();
        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        $counts[$extension] = ($counts[$extension] ?? 0) + 1;
        $this->skipped_files = json_encode($counts);
    }

    // Most common extension first
    public function getSkippedFileCounts()
    {
        $counts = $this->skipped_files ? json_decode($this->skipped_files, true) : [];
        arsort($counts);
        return $counts;
    }

    public function getResourceId()
    {
        return 'Archivematica_Import';
    }
}
