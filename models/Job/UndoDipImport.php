<?php
class Job_UndoDipImport extends Omeka_Job_AbstractJob
{
    public function perform()
    {
        $import = $this->_db->getTable('ArchivematicaImport')->find($this->_options['archivematica_import_id']);
        if (!$import) {
            _log(sprintf(
                'Archivematica: no ArchivematicaImport record found for id %s',
                $this->_options['archivematica_import_id']
            ), Zend_Log::ERR);
            return;
        }

        try {
            $deletedItemCount = 0;
            $deletedFileCount = 0;
            $archivematicaItems = $this->_db->getTable('ArchivematicaItem')->findBy(['import_id' => $import->id]);
            foreach ($archivematicaItems as $archivematicaItem) {
                $item = $this->_db->getTable('Item')->find($archivematicaItem->item_id);
                if (!$item) {
                    $archivematicaItem->delete();
                    continue;
                }
                $deletedFileCount += $item->fileCount();
                // Also deletes the tracking row, via hookBeforeDeleteItem
                $item->delete();
                $deletedItemCount++;
            }

            $import->comment = implode('; ', array_filter([
                $import->comment,
                $deletedItemCount ? __(plural('%s item deleted', '%s items deleted', $deletedItemCount), $deletedItemCount) : null,
                $deletedFileCount ? __(plural('%s file deleted', '%s files deleted', $deletedFileCount), $deletedFileCount) : null,
            ]));
            $import->setStatus(ArchivematicaImport::STATUS_UNDONE);
        } catch (\Throwable $e) {
            $import->error_message = $e->getMessage();
            $import->setStatus(Process::STATUS_ERROR);
            _log(sprintf("%s\n%s", $e->getMessage(), $e->getTraceAsString()), Zend_Log::ERR);
        }
        $import->save();
    }
}
