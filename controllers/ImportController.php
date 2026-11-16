<?php
require_once dirname(__FILE__) . '/../forms/Import.php';

class Archivematica_ImportController extends Omeka_Controller_AbstractActionController
{
    public function init()
    {
        $this->_helper->db->setDefaultModelName('ArchivematicaImport');
    }

    public function browseAction()
    {
        $this->view->csrf = new Omeka_Form_SessionCsrf;
        parent::browseAction();
    }

    public function addAction()
    {
        $form = new Archivematica_Form_Import;
        $csrf = new Omeka_Form_SessionCsrf;
        $this->view->form = $form;
        $this->view->csrf = $csrf;
        if (!$this->getRequest()->isPost()) {
            return;
        }
        if (!$csrf->isValid($_POST) || !$form->isValid($_POST)) {
            $this->_helper->flashMessenger(__('There were errors found in your form. Please edit and resubmit.'), 'error');
            return;
        }

        // PharData needs the .tar extension to open the staged file
        $dipPath = sprintf('%s/archivematica_dip_%s.tar', sys_get_temp_dir(), uniqid());
        if (!move_uploaded_file($_FILES['dip_file']['tmp_name'], $dipPath)) {
            $this->_helper->flashMessenger(__('Could not save the uploaded file.'), 'error');
            return;
        }

        $import = new ArchivematicaImport;
        $import->original_filename = $_FILES['dip_file']['name'];
        $import->comment = $form->getValue('comment');
        $import->collection_id = $form->getValue('collection_id') ?: null;
        $import->public = (int) (bool) $form->getValue('public');
        $import->setStatus(Process::STATUS_STARTING);
        $import->save();

        $dispatcher = Zend_Registry::get('job_dispatcher');
        $dispatcher->sendLongRunning('Job_ImportDip', [
            'archivematica_import_id' => $import->id,
            'dip_path' => $dipPath,
        ]);

        $this->_helper->flashMessenger(__('Importing the DIP.'), 'success');
        $this->_helper->redirector('browse');
    }

    public function undoAction()
    {
        $csrf = new Omeka_Form_SessionCsrf;
        if (!$this->getRequest()->isPost() || !$csrf->isValid($_POST)) {
            $this->_helper->flashMessenger(__('There were errors found in your form. Please edit and resubmit.'), 'error');
            $this->_helper->redirector('browse');
            return;
        }

        $import = $this->_helper->db->findById();
        if (!$import->canUndo()) {
            $this->_helper->flashMessenger(__('Only completed or failed imports can be undone.'), 'error');
            $this->_helper->redirector('browse');
            return;
        }
        $import->setStatus(ArchivematicaImport::STATUS_UNDO_IN_PROGRESS);
        $import->save();

        $dispatcher = Zend_Registry::get('job_dispatcher');
        $dispatcher->sendLongRunning('Job_UndoDipImport', ['archivematica_import_id' => $import->id]);

        $this->_helper->flashMessenger(__('Undoing the import.'), 'success');
        $this->_helper->redirector('browse');
    }

    protected function _getBrowseDefaultSort()
    {
        return ['id', 'd'];
    }
}
