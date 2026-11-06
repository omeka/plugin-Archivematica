<?php
abstract class Job_AbstractArchivematicaSip extends Omeka_Job_AbstractJob
{
    const MAX_FIELD_LENGTH = 100000;

    public function perform()
    {
        $sip = $this->_db->getTable('ArchivematicaSip')->find($this->_options['archivematica_sip_id']);
        if (!$sip) {
            _log(sprintf(
                'Archivematica: no ArchivematicaSip record found for id %s',
                $this->_options['archivematica_sip_id']
            ), Zend_Log::ERR);
            return;
        }

        try {
            $sip->setStatus(Process::STATUS_IN_PROGRESS);
            $sip->save();

            $sip->sip_name = $this->_buildSip($sip);
            $sip->setStatus(Process::STATUS_COMPLETED);
            $sip->save();
        } catch (\Throwable $e) {
            $sip->error_message = $e->getMessage();
            $sip->setStatus(Process::STATUS_ERROR);
            $sip->save();
            _log(sprintf("%s\n%s", $e->getMessage(), $e->getTraceAsString()), Zend_Log::ERR);
        }
    }

    // Build the SIP zip for the record and return the SIP name
    abstract protected function _buildSip(ArchivematicaSip $sip);

    // Create empty objects/ and metadata/ directories, removing any previous SIP with this name
    protected function _prepareSipDirectory($sipName)
    {
        $sipDirectoryPath = sprintf('%s/%s', $this->_getSipsDirectoryPath(), $sipName);
        $sipZipPath = sprintf('%s.zip', $sipDirectoryPath);
        if (is_file($sipZipPath)) {
            unlink($sipZipPath);
        }
        if (is_dir($sipDirectoryPath)) {
            $this->_shellExec(sprintf('rm -r %s', escapeshellarg($sipDirectoryPath)));
        }
        mkdir(sprintf('%s/objects', $sipDirectoryPath), 0755, true);
        mkdir(sprintf('%s/metadata', $sipDirectoryPath), 0755, true);
        return $sipDirectoryPath;
    }

    protected function _zipSipDirectory($sipName)
    {
        $sipDirectoryPath = sprintf('%s/%s', $this->_getSipsDirectoryPath(), $sipName);
        // Archivematica expects objects/ and metadata/ at the zip root, not under a wrapping directory
        $this->_shellExec(sprintf(
            'cd %s && zip --recurse-paths %s objects metadata',
            escapeshellarg($sipDirectoryPath),
            escapeshellarg(sprintf('%s.zip', $sipDirectoryPath))
        ));
        $this->_shellExec(sprintf('rm -r %s', escapeshellarg($sipDirectoryPath)));
    }

    protected function _getSipsDirectoryPath()
    {
        $sipsDirectoryPath = get_option('archivematica_sips_directory_path');
        if (!$sipsDirectoryPath) {
            throw new Exception('Archivematica SIPs directory path is not configured.');
        }
        return $sipsDirectoryPath;
    }

    protected function _shellExec($command)
    {
        $output = shell_exec($command);
        if (false === $output) {
            throw new Exception(sprintf('Archivematica: invalid command: %s', $command));
        }
    }

    /**
     * Archivematica parses metadata.csv with Python's csv module, which has a
     * hard-coded 131072-byte field size limit. Exceeding it aborts METS
     * generation for the whole SIP, so values are truncated well below that.
     */
    protected function _truncateFieldValue($value)
    {
        if (strlen($value) > self::MAX_FIELD_LENGTH) {
            return substr($value, 0, self::MAX_FIELD_LENGTH) . '... [truncated]';
        }
        return $value;
    }

    /**
     * Remove characters that lxml (used by Archivematica's METS generation)
     * refuses to serialize as XML text, such as the form feed page breaks
     * OCR/PDF text extraction leaves behind. Left in place, one aborts METS
     * generation for the whole SIP with "All strings must be XML compatible".
     */
    protected function _stripInvalidXmlChars($value)
    {
        return preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $value);
    }
}
