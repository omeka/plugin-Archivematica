<?php
class Job_PackageStaticSiteSip extends Job_AbstractArchivematicaSip
{
    protected function _buildSip(ArchivematicaSip $sip)
    {
        if ($sip->source_type !== 'static_site') {
            throw new Exception(sprintf('Unsupported source type: %s', $sip->source_type));
        }

        $staticSite = $this->_db->getTable('StaticSite')->find($sip->source_id);
        if (!$staticSite) {
            throw new Exception('Static site export not found.');
        }

        $sitesDirectoryPath = get_option('static_site_export_sites_directory_path');
        $sourceZipPath = sprintf('%s/%s.zip', $sitesDirectoryPath, $staticSite->getName());
        if (!is_file($sourceZipPath)) {
            throw new Exception(sprintf('Static site export zip not found: %s', $sourceZipPath));
        }

        $sipName = sprintf('%s-%d-AM_SIP', $staticSite->getName(), $sip->id);
        $sipDirectoryPath = $this->_prepareSipDirectory($sipName);
        $objectFilename = sprintf('objects/%s.zip', $staticSite->getName());
        if (!copy($sourceZipPath, sprintf('%s/%s', $sipDirectoryPath, $objectFilename))) {
            throw new Exception(sprintf('Could not copy static site export zip into SIP: %s', $sourceZipPath));
        }

        $this->_writeStaticSiteMetadataCsv($sipDirectoryPath, $objectFilename, $staticSite);
        $this->_zipSipDirectory($sipName);
        return $sipName;
    }

    // Write a single metadata.csv row describing the whole static site export
    private function _writeStaticSiteMetadataCsv($sipDirectoryPath, $objectFilename, $staticSite)
    {
        $fp = fopen(sprintf('%s/metadata/metadata.csv', $sipDirectoryPath), 'w');
        fputcsv($fp, ['filename', 'dc.title', 'dc.description', 'dc.identifier'], ',', '"', '');
        fputcsv($fp, [
            $objectFilename,
            $this->_stripInvalidXmlChars((string) get_option('site_title')),
            $this->_stripInvalidXmlChars((string) get_option('description')),
            $this->_stripInvalidXmlChars((string) $staticSite->getDataValue('base_url')),
        ], ',', '"', '');
        fclose($fp);
    }
}
