<?php

/*
 * This file is part of Sulu.
 *
 * (c) MASSIVE ART WebServices GmbH
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace Sulu\Bundle\ArticleBundle\Document\Index;

use ONGR\ElasticsearchBundle\Collection\Collection;
use ONGR\ElasticsearchBundle\Service\Manager;
use ONGR\ElasticsearchDSL\Query\MatchAllQuery;
use ONGR\ElasticsearchDSL\Query\TermLevel\TermQuery;
use Sulu\Bundle\ArticleBundle\Document\ArticleDocument;
use Sulu\Bundle\ArticleBundle\Document\ArticleViewDocumentInterface;
use Sulu\Bundle\ArticleBundle\Document\Index\Factory\ExcerptFactory;
use Sulu\Bundle\ArticleBundle\Document\Index\Factory\SeoFactory;
use Sulu\Bundle\ArticleBundle\Document\LocalizationStateViewObject;
use Sulu\Bundle\ArticleBundle\Event\Events;
use Sulu\Bundle\ArticleBundle\Event\IndexEvent;
use Sulu\Bundle\ArticleBundle\Metadata\ArticleTypeTrait;
use Sulu\Bundle\ArticleBundle\Metadata\ArticleViewDocumentIdTrait;
use Sulu\Bundle\ContactBundle\Entity\ContactRepository;
use Sulu\Bundle\SecurityBundle\UserManager\UserManager;
use Sulu\Component\Content\Document\LocalizationState;
use Sulu\Component\Content\Document\WorkflowStage;
use Sulu\Component\Content\Metadata\Factory\StructureMetadataFactoryInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Translation\TranslatorInterface;

/**
 * Provides methods to index articles.
 */
class ArticleIndexer implements IndexerInterface
{
    use ArticleTypeTrait;
    use ArticleViewDocumentIdTrait;

    /**
     * @var StructureMetadataFactoryInterface
     */
    protected $structureMetadataFactory;

    /**
     * @var UserManager
     */
    protected $userManager;

    /**
     * @var ContactRepository
     */
    protected $contactRepository;

    /**
     * @var DocumentFactoryInterface
     */
    protected $documentFactory;

    /**
     * @var Manager
     */
    protected $manager;

    /**
     * @var ExcerptFactory
     */
    protected $excerptFactory;

    /**
     * @var SeoFactory
     */
    protected $seoFactory;

    /**
     * @var EventDispatcherInterface
     */
    protected $eventDispatcher;

    /**
     * @var TranslatorInterface
     */
    protected $translator;

    /**
     * @var array
     */
    protected $typeConfiguration;

    /**
     * @param StructureMetadataFactoryInterface $structureMetadataFactory
     * @param UserManager $userManager
     * @param ContactRepository $contactRepository
     * @param DocumentFactoryInterface $documentFactory
     * @param Manager $manager
     * @param ExcerptFactory $excerptFactory
     * @param SeoFactory $seoFactory
     * @param EventDispatcherInterface $eventDispatcher
     * @param TranslatorInterface $translator
     * @param array $typeConfiguration
     */
    public function __construct(
        StructureMetadataFactoryInterface $structureMetadataFactory,
        UserManager $userManager,
        ContactRepository $contactRepository,
        DocumentFactoryInterface $documentFactory,
        Manager $manager,
        ExcerptFactory $excerptFactory,
        SeoFactory $seoFactory,
        EventDispatcherInterface $eventDispatcher,
        TranslatorInterface $translator,
        array $typeConfiguration
    ) {
        $this->structureMetadataFactory = $structureMetadataFactory;
        $this->userManager = $userManager;
        $this->contactRepository = $contactRepository;
        $this->documentFactory = $documentFactory;
        $this->manager = $manager;
        $this->excerptFactory = $excerptFactory;
        $this->seoFactory = $seoFactory;
        $this->eventDispatcher = $eventDispatcher;
        $this->translator = $translator;
        $this->typeConfiguration = $typeConfiguration;
    }

    /**
     * Returns translation for given article type.
     *
     * @param string $type
     *
     * @return string
     */
    private function getTypeTranslation($type)
    {
        if (!array_key_exists($type, $this->typeConfiguration)) {
            return ucfirst($type);
        }

        $typeTranslationKey = $this->typeConfiguration[$type]['translation_key'];

        return $this->translator->trans($typeTranslationKey, [], 'backend');
    }

    /**
     * @param ArticleDocument $document
     * @param ArticleViewDocumentInterface $article
     */
    protected function dispatchIndexEvent(ArticleDocument $document, ArticleViewDocumentInterface $article)
    {
        $this->eventDispatcher->dispatch(Events::INDEX_EVENT, new IndexEvent($document, $article));
    }

    /**
     * @param ArticleDocument $document
     * @param string $locale
     * @param string $localizationState
     *
     * @return ArticleViewDocumentInterface
     */
    protected function createOrUpdateArticle(
        ArticleDocument $document,
        $locale,
        $localizationState = LocalizationState::LOCALIZED
    ) {
        $article = $this->findOrCreateViewDocument($document, $locale, $localizationState);
        if (!$article) {
            return;
        }

        $structureMetadata = $this->structureMetadataFactory->getStructureMetadata(
            'article',
            $document->getStructureType()
        );

        $article->setTitle($document->getTitle());
        $article->setRoutePath($document->getRoutePath());
        $article->setChanged($document->getChanged());
        $article->setCreated($document->getCreated());
        $article->setAuthored($document->getAuthored());
        if ($document->getAuthor() && $author = $this->contactRepository->find($document->getAuthor())) {
            $article->setAuthorFullName($author->getFullName());
            $article->setAuthorId($author->getId());
        }
        if ($document->getChanger() && $changer = $this->userManager->getUserById($document->getChanger())) {
            $article->setChangerFullName($changer->getFullName());
            $article->setChangerContactId($changer->getContact()->getId());
        }
        if ($document->getCreator() && $creator = $this->userManager->getUserById($document->getCreator())) {
            $article->setCreatorFullName($creator->getFullName());
            $article->setCreatorContactId($creator->getContact()->getId());
        }
        $article->setType($this->getType($structureMetadata));
        $article->setStructureType($document->getStructureType());
        $article->setPublished($document->getPublished());
        $article->setPublishedState($document->getWorkflowStage() === WorkflowStage::PUBLISHED);
        $article->setTypeTranslation($this->getTypeTranslation($this->getType($structureMetadata)));
        $article->setLocalizationState(
            new LocalizationStateViewObject(
                $localizationState,
                (LocalizationState::LOCALIZED === $localizationState) ? null : $document->getLocale()
            )
        );

        $extensions = $document->getExtensionsData()->toArray();
        if (array_key_exists('excerpt', $extensions)) {
            $article->setExcerpt($this->excerptFactory->create($extensions['excerpt'], $document->getLocale()));
        }
        if (array_key_exists('seo', $extensions)) {
            $article->setSeo($this->seoFactory->create($extensions['seo']));
        }
        if ($structureMetadata->hasPropertyWithTagName('sulu.teaser.description')) {
            $descriptionProperty = $structureMetadata->getPropertyByTagName('sulu.teaser.description');
            $article->setTeaserDescription(
                $document->getStructure()->getProperty($descriptionProperty->getName())->getValue()
            );
        }
        if ($structureMetadata->hasPropertyWithTagName('sulu.teaser.media')) {
            $mediaProperty = $structureMetadata->getPropertyByTagName('sulu.teaser.media');
            $mediaData = $document->getStructure()->getProperty($mediaProperty->getName())->getValue();
            if (null !== $mediaData && array_key_exists('ids', $mediaData)) {
                $article->setTeaserMediaId(reset($mediaData['ids']) ?: null);
            }
        }

        $this->mapPages($document, $article);

        return $article;
    }

    /**
     * Returns view-document from index or create a new one.
     *
     * @param ArticleDocument $document
     * @param string $locale
     * @param string $localizationState
     *
     * @return ArticleViewDocumentInterface
     */
    protected function findOrCreateViewDocument(ArticleDocument $document, $locale, $localizationState)
    {
        $articleId = $this->getViewDocumentId($document->getUuid(), $locale);
        /** @var ArticleViewDocumentInterface $article */
        $article = $this->manager->find($this->documentFactory->getClass('article'), $articleId);

        if ($article) {
            // Only index ghosts when the article isn't a ghost himself.
            if (LocalizationState::GHOST === $localizationState
                && LocalizationState::GHOST !== $article->getLocalizationState()->state
            ) {
                return null;
            }

            return $article;
        }

        $article = $this->documentFactory->create('article');
        $article->setId($articleId);
        $article->setUuid($document->getUuid());
        $article->setLocale($locale);

        return $article;
    }

    /**
     * Maps pages from document to view-document.
     *
     * @param ArticleDocument $document
     * @param ArticleViewDocumentInterface $article
     */
    private function mapPages(ArticleDocument $document, ArticleViewDocumentInterface $article)
    {
        $pages = [];
        foreach ($document->getChildren() as $child) {
            $pages[] = $page = $this->documentFactory->create('article_page');
            $page->uuid = $child->getUuid();
            $page->pageNumber = $child->getPageNumber();
            $page->title = $child->getPageTitle();
            $page->routePath = $child->getRoutePath();
        }

        $article->setPages(new Collection($pages));
    }

    /**
     * @param string $id
     */
    protected function removeArticle($id)
    {
        $article = $this->manager->find(
            $this->documentFactory->getClass('article'),
            $id
        );
        if (null === $article) {
            return;
        }

        $this->manager->remove($article);
    }

    /**
     * {@inheritdoc}
     */
    public function remove($document)
    {
        $repository = $this->manager->getRepository($this->documentFactory->getClass('article'));
        $search = $repository->createSearch()
            ->addQuery(new TermQuery('uuid', $document->getUuid()))
            ->setSize(1000);
        foreach ($repository->findDocuments($search) as $viewDocument) {
            $this->manager->remove($viewDocument);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function flush()
    {
        $this->manager->commit();
    }

    /**
     * {@inheritdoc}
     */
    public function clear()
    {
        $pageSize = 500;
        $repository = $this->manager->getRepository($this->documentFactory->getClass('article'));
        $search = $repository->createSearch()
            ->addQuery(new MatchAllQuery())
            ->setSize($pageSize);

        do {
            $result = $repository->findDocuments($search);
            foreach ($result as $document) {
                $this->manager->remove($document);
            }

            $this->manager->commit();
        } while ($result->count() !== 0);

        $this->manager->clearCache();
        $this->manager->flush();
    }

    /**
     * {@inheritdoc}
     */
    public function setUnpublished($uuid)
    {
        $article = $this->manager->find($this->documentFactory->getClass('article'), $uuid);

        if (!$article) {
            return;
        }

        $article->setPublished(null);
        $article->setPublishedState(false);

        $this->manager->persist($article);
    }

    /**
     * {@inheritdoc}
     */
    public function index(ArticleDocument $document)
    {
        $article = $this->createOrUpdateArticle($document, $document->getLocale());
        $this->dispatchIndexEvent($document, $article);
        $this->manager->persist($article);
    }
}
