<?php

/*
 * Copyright 2015-2020 Daniel Berthereau
 * Copyright 2016-2017 BibLibre
 *
 * This software is governed by the CeCILL license under French law and abiding
 * by the rules of distribution of free software. You can use, modify and/or
 * redistribute the software under the terms of the CeCILL license as circulated
 * by CEA, CNRS and INRIA at the following URL "http://www.cecill.info".
 *
 * As a counterpart to the access to the source code and rights to copy, modify
 * and redistribute granted by the license, users are provided only with a
 * limited warranty and the software’s author, the holder of the economic
 * rights, and the successive licensors have only limited liability.
 *
 * In this respect, the user’s attention is drawn to the risks associated with
 * loading, using, modifying and/or developing or reproducing the software by
 * the user in light of its specific status of free software, that may mean that
 * it is complicated to manipulate, and that also therefore means that it is
 * reserved for developers and experienced professionals having in-depth
 * computer knowledge. Users are therefore encouraged to load and test the
 * software’s suitability as regards their requirements in conditions enabling
 * the security of their systems and/or data to be ensured and, more generally,
 * to use and operate it in the same conditions as regards security.
 *
 * The fact that you are presently reading this means that you have had
 * knowledge of the CeCILL license and that you accept its terms.
 */

namespace ImageServer\Controller;

use Omeka\File\Store\StoreInterface;
use Zend\Mvc\Controller\AbstractActionController;
use Zend\View\Model\ViewModel;

/**
 * The Media controller class.
 *
 * @package ImageServer
 */
class MediaController extends AbstractActionController
{
    /**
     * @var StoreInterface
     */
    protected $store;

    /**
     * Full path to the files.
     *
     * @var string
     */
    protected $basePath;

    public function __construct($store, $basePath)
    {
        $this->store = $store;
        $this->basePath = $basePath;
    }

    /**
     * Redirect to the 'info' action, required by the feature "baseUriRedirect".
     *
     * @see self::infoAction()
     */
    public function indexAction()
    {
        $id = $this->params('id');
        $url = $this->url()->fromRoute('mediaserver/info', ['id' => $id], ['force_canonical' => true]);
        return $this->getResponse()
            // TODO The iiif image api specification recommands 303, not 302.
            ->setStatusCode(\Zend\Http\Response::STATUS_CODE_303)
            ->getHeaders()->addHeaderLine('Location', $url);
    }

    /**
     * Send "info.json" for the current file.
     *
     * The info is managed by the MediaControler because it indicates
     * capabilities of the IXIF server for the request of a file.
     */
    public function infoAction()
    {
        // Not found exception is automatically thrown.
        $id = $this->params('id');
        $media = $this->api()->read('media', $id)->getContent();

        $version = $this->requestedVersion();

        $iiifInfo = $this->viewHelpers()->get('iiifInfo');
        $info = $iiifInfo($media, $version);

        return $this->iiifImageJsonLd($info, $version);
    }

    /**
     * Returns an error 400 to requests that are invalid.
     */
    public function badAction()
    {
        $response = $this->getResponse();
        $response->setStatusCode(400);

        $view = new ViewModel;
        return $view
            ->setVariable('message', $this->translate('The Image server cannot fulfill the request: the arguments are incorrect.'))
            ->setTemplate('iiif-server/image/error');
    }

    /**
     * Returns the current file.
     */
    public function fetchAction()
    {
        // Not found exception is automatically thrown.
        $id = $this->params('id');
        $media = $this->api()->read('media', $id)->getContent();

        $response = $this->getResponse();

        // Because there is no conversion currently, the format should be
        // checked.
        $format = strtolower($this->params('format'));
        if (pathinfo($media->filename(), PATHINFO_EXTENSION) != $format) {
            $response->setStatusCode(500);

            $view = new viewModel;
            return $view
                ->setVariable('message', $this->translate('The IXIF server encountered an unexpected error that prevented it from fulfilling the request: the requested format is not supported.'))
                ->setTemplate('iiif-server/image/error');
        }

        // A check is added if the file is local: the source can be a local file
        // or an external one (Amazon S3…).
        switch (get_class($this->store)) {
            case \Omeka\File\Store\Local::class:
                $filepath = $this->basePath
                    . DIRECTORY_SEPARATOR . $this->getStoragePath('original', $media->filename());
                if (!file_exists($filepath) || filesize($filepath) == 0) {
                    $response->setStatusCode(500);

                    $view = new ViewModel;
                    return $view
                        ->setVariable('message', $this->translate('The IXIF server encountered an unexpected error that prevented it from fulfilling the request: the resulting file is not found.'))
                        ->setTemplate('iiif-server/image/error');
                }
                break;
        }
        // TODO Check if the external url is not empty.

        // Header for CORS, required for access of IXIF.
        $response->getHeaders()
            ->addHeaderLine('access-control-allow-origin', '*')
            ->getHeaders()->addHeaderLine('Content-Type', $media->mediaType());

        // TODO This is a local file (normal server): use 200.

        // Redirect (302/307) to the url of the file.
        $fileurl = $media->originalUrl();
        return $this->redirect()->toUrl($fileurl);
    }

    /**
     * Get a storage path.
     *
     * @param string $prefix The storage prefix
     * @param string $name The file name, or basename if extension is passed
     * @param string|null $extension The file extension
     * @return string
     * @todo Refactorize.
     */
    protected function getStoragePath($prefix, $name, $extension = null)
    {
        return sprintf('%s/%s%s', $prefix, $name, $extension ? ".$extension" : null);
    }

    /**
     * Get the requested version from the headers.
     *
     * @todo Factorize with ImageController::requestedVersion()
     *
     * @return string|null
     */
    protected function requestedVersion()
    {
        $accept = $this->getRequest()->getHeaders()->get('Accept')->toString();
        if (strpos($accept, 'iiif.io/api/image/3/context.json')) {
            return '3.0';
        }
        if (strpos($accept, 'iiif.io/api/image/2/context.json')) {
            return '2.1';
        }
        return null;
    }
}
