<?php
namespace ApacheSolrForTypo3\Solr\Domain\Search\ApacheSolrDocument;

/***************************************************************
 *  Copyright notice
 *
 *  (c) 2017 Timo Hund <timo.hund@dkd.de>
 *  All rights reserved
 *
 *  This script is part of the TYPO3 project. The TYPO3 project is
 *  free software; you can redistribute it and/or modify
 *  it under the terms of the GNU General Public License as published by
 *  the Free Software Foundation; either version 2 of the License, or
 *  (at your option) any later version.
 *
 *  The GNU General Public License can be found at
 *  http://www.gnu.org/copyleft/gpl.html.
 *
 *  This script is distributed in the hope that it will be useful,
 *  but WITHOUT ANY WARRANTY; without even the implied warranty of
 *  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 *  GNU General Public License for more details.
 *
 *  This copyright notice MUST APPEAR in all copies of the script!
 ***************************************************************/

use Apache_Solr_Document;
use ApacheSolrForTypo3\Solr\Access\Rootline;
use ApacheSolrForTypo3\Solr\Domain\Site\SiteRepository;
use ApacheSolrForTypo3\Solr\Domain\Variants\IdBuilder;
use ApacheSolrForTypo3\Solr\Site;
use ApacheSolrForTypo3\Solr\Typo3PageContentExtractor;
use ApacheSolrForTypo3\Solr\Util;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Frontend\Controller\TypoScriptFrontendController;

/**
 * Builder class to build an ApacheSolrDocument
 *
 * Responsible to build Apache_Solr_Documents
 *
 * @author Timo Hund <timo.hund@dkd.de>
 */
class Builder
{
    /**
     * @var IdBuilder
     */
    protected $variantIdBuilder;

    /**
     * Builder constructor.
     * @param IdBuilder|null $variantIdBuilder
     */
    public function __construct(IdBuilder $variantIdBuilder = null)
    {
        $this->variantIdBuilder = is_null($variantIdBuilder) ? GeneralUtility::makeInstance(IdBuilder::class) : $variantIdBuilder;
    }

    /**
     * This method can be used to build an Apache_Solr_Document from a TYPO3 page.
     *
     * @param TypoScriptFrontendController $page
     * @param string $url
     * @param Rootline $pageAccessRootline
     * @param string $mountPointParameter
     * @return Apache_Solr_Document|object
     */
    public function fromPage(TypoScriptFrontendController $page, $url, Rootline $pageAccessRootline, $mountPointParameter)
    {
        /* @var $document \Apache_Solr_Document */
        $document = GeneralUtility::makeInstance(Apache_Solr_Document::class);
        $site = $this->getSiteByPageId($page->id);
        $pageRecord = $page->page;

        $accessGroups = $this->getDocumentIdGroups($pageAccessRootline);
        $documentId = $this->getPageDocumentId($page, $accessGroups, $mountPointParameter);

        $document->setField('id', $documentId);
        $document->setField('site', $site->getDomain());
        $document->setField('siteHash', $site->getSiteHash());
        $document->setField('appKey', 'EXT:solr');
        $document->setField('type', 'pages');

        // system fields
        $document->setField('uid', $page->id);
        $document->setField('pid', $pageRecord['pid']);

        // variantId
        $variantId = $this->variantIdBuilder->buildFromTypeAndUid('pages', $page->id);
        $document->setField('variantId', $variantId);

        $document->setField('typeNum', $page->type);
        $document->setField('created', $pageRecord['crdate']);
        $document->setField('changed', $pageRecord['SYS_LASTCHANGED']);

        $rootline = $this->getRootLineFieldValue($page->id, $mountPointParameter);
        $document->setField('rootline', $rootline);

        // access
        $this->addAccessField($document, $pageAccessRootline);
        $this->addEndtimeField($document, $pageRecord);

        // content
        $contentExtractor = $this->getExtractorForPageContent($page->content);
        $document->setField('title', $contentExtractor->getPageTitle());
        $document->setField('subTitle', $pageRecord['subtitle']);
        $document->setField('navTitle', $pageRecord['nav_title']);
        $document->setField('author', $pageRecord['author']);
        $document->setField('description', $pageRecord['description']);
        $document->setField('abstract', $pageRecord['abstract']);
        $document->setField('content', $contentExtractor->getIndexableContent());
        $document->setField('url', $url);

        $this->addKeywordsField($document, $pageRecord);
        $this->addTagContentFields($document, $contentExtractor->getTagContent());

        return $document;
    }

    /**
     * @param TypoScriptFrontendController  $page
     * @param string $accessGroups
     * @param string $mountPointParameter
     * @return string
     */
    protected function getPageDocumentId($page, $accessGroups, $mountPointParameter)
    {
        return Util::getPageDocumentId($page->id, $page->type, $page->sys_language_uid, $accessGroups, $mountPointParameter);
    }

    /**
     * @param integer $pageId
     * @return Site
     */
    protected function getSiteByPageId($pageId)
    {
        $siteRepository = GeneralUtility::makeInstance(SiteRepository::class);
        return $siteRepository->getSiteByPageId($pageId);
    }

    /**
     * @param string $pageContent
     * @return Typo3PageContentExtractor
     */
    protected function getExtractorForPageContent($pageContent)
    {
        return GeneralUtility::makeInstance(Typo3PageContentExtractor::class, $pageContent);
    }

    /**
     * Builds the content for the rootline field.
     *
     * @param int $pageId
     * @param string $mountPointParameter
     * @return string
     */
    protected function getRootLineFieldValue($pageId, $mountPointParameter)
    {
        $rootline = $pageId;
        if ($mountPointParameter !== '') {
            $rootline .= ',' . $mountPointParameter;
        }
        return $rootline;
    }

    /**
     * Gets a comma separated list of frontend user groups to use for the
     * document ID.
     *
     * @param Rootline $pageAccessRootline
     * @return string A comma separated list of frontend user groups.
     */
    protected function getDocumentIdGroups(Rootline $pageAccessRootline)
    {
        $groups = $pageAccessRootline->getGroups();
        $groups = Rootline::cleanGroupArray($groups);

        if (empty($groups)) {
            $groups[] = 0;
        }

        $groups = implode(',', $groups);

        return $groups;
    }

    /**
     * Adds the access field to the document if needed.
     *
     * @param \Apache_Solr_Document $document
     * @param Rootline $pageAccessRootline
     */
    protected function addAccessField(\Apache_Solr_Document $document, Rootline $pageAccessRootline)
    {
        $access = (string)$pageAccessRootline;
        if (trim($access) !== '') {
            $document->setField('access', $access);
        }
    }

    /**
     * Adds the endtime field value to the Apache_Solr_Document.
     *
     * @param \Apache_Solr_Document $document
     * @param array $pageRecord
     */
    protected function addEndtimeField(\Apache_Solr_Document  $document, $pageRecord)
    {
        if ($pageRecord['endtime']) {
            $document->setField('endtime', $pageRecord['endtime']);
        }
    }

    /**
     * Adds keywords, multi valued.
     *
     * @param \Apache_Solr_Document $document
     * @param array $pageRecord
     */
    protected function addKeywordsField(\Apache_Solr_Document $document, $pageRecord)
    {
        if (!isset($pageRecord['keywords'])) {
            return;
        }

        $keywords = array_unique(GeneralUtility::trimExplode(',', $pageRecord['keywords'], true));
        foreach ($keywords as $keyword) {
            $document->addField('keywords', $keyword);
        }
    }

    /**
     * Add content from several tags like headers, anchors, ...
     *
     * @param \Apache_Solr_Document $document
     * @param array $tagContent
     */
    protected function addTagContentFields(\Apache_Solr_Document  $document, $tagContent = [])
    {
        foreach ($tagContent as $fieldName => $fieldValue) {
            $document->setField($fieldName, $fieldValue);
        }
    }
}
