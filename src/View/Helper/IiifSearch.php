<?php declare(strict_types=1);

namespace IiifSearch\View\Helper;

use IiifSearch\Iiif\AnnotationList;
use IiifSearch\Iiif\AnnotationSearchResult;
use IiifSearch\Iiif\SearchHit;
use IiifServer\Mvc\Controller\Plugin\ImageSize;
use Laminas\Log\Logger;
use Laminas\View\Helper\AbstractHelper;
use Omeka\Api\Representation\ItemRepresentation;
use Omeka\Api\Representation\MediaRepresentation;
use SimpleXMLElement;

class IiifSearch extends AbstractHelper
{
    /**
     * @var array
     */
    protected $supportedMediaTypes = [
        'application/alto+xml',
        'application/vnd.pdf2xml+xml',
    ];

    /**
     * @var int
     */
    protected $minimumQueryLength = 3;

    /**
     * @var \Laminas\Log\Logger
     */
    protected $logger;

    /**
     * @var \IiifServer\Mvc\Controller\Plugin\FixUtf8
     */
    protected $fixUtf8;

    /**
     * @var \IiifServer\Mvc\Controller\Plugin\ImageSize
     */
    protected $imageSize;

    /**
     * Full path to the files.
     *
     * @var string
     */
    protected $basePath;

    /**
     * @var ItemRepresentation
     */
    protected $item;

    /**
     * @var \Omeka\Api\Representation\MediaRepresentation[]
     */
    protected $xmlFiles;

    /**
     * @var \Omeka\Api\Representation\MediaRepresentation
     */
    protected $firstXmlFile;

    /**
     * @var string
     */
    protected $mediaType;

    /**
     * @var array
     */
    protected $imageSizes;

    public function __construct(Logger $logger, ?FixUtf8 $fixUtf8, ?ImageSize $imageSize, $basePath)
    {
        $this->logger = $logger;
        $this->fixUtf8 = $fixUtf8;
        $this->imageSize = $imageSize;
        $this->basePath = $basePath;
    }

    /**
     * Get the IIIF search response for fulltext research query.
     *
     * @param ItemRepresentation $item
     * @return AnnotationList|null Null is returned if search is not supported
     * for the resource.
     */
    public function __invoke(ItemRepresentation $item): ?AnnotationList
    {
        $this->item = $item;

        if (!$this->prepareSearch()) {
            return null;
        }

        // TODO Add a warning when the number of images is not the same than the number of pages. But it may be complex because images are not really managed with xml files, so warn somewhere else.

        $view = $this->getView();
        $query = (string) $view->params()->fromQuery('q');

        $result = $this->searchFulltext($query);

        $response = new AnnotationList;
        $response->initOptions(['requestUri' => $view->serverUrl(true)]);
        if ($result) {
            $response['resources'] = $result['resources'];
            $response['hits'] = $result['hits'];
        }
        $response->isValid(true);
        return $response;
    }

    /**
     * Returns answers to a query.
     *
     * @todo add xml validation ( pdf filename == xml filename according to Extract Ocr plugin )
     *
     * @return array|null
     *  Return resources that match query for IIIF Search API
     * [
     *      [
     *          '@id' => 'https://your_domain.com/omeka-s/iiif-search/itemID/searchResults/ . a . numCanvas . h . numresult. r .  xCoord , yCoord, wCoord , hCoord ',
     *          '@type' => 'oa:Annotation',
     *          'motivation' => 'sc:painting',
     *          [
     *              '@type' => 'cnt:ContentAsText',
     *              'chars' =>  corresponding match char list ,
     *          ]
     *          'on' => canvas url with coordonate for IIIF Server module,
     *      ]
     *      ...
     */
    protected function searchFulltext(string $query): ?array
    {
        if (!strlen($query)) {
            return null;
        }

        $queryWords = $this->formatQuery($query);
        if (empty($queryWords)) {
            return null;
        }

        $xml = $this->loadXml();
        if (empty($xml)) {
            return null;
        }

        if ($this->mediaType === 'application/alto+xml') {
            return $this->searchFullTextAlto($xml, $queryWords);
        } elseif ($this->mediaType === 'application/vnd.pdf2xml+xml') {
            return $this->searchFullTextPdfXml($xml, $queryWords);
        } else {
            return null;
        }
    }

    protected function searchFullTextAlto(SimpleXmlElement $xml, $queryWords): ?array
    {
        $result = [
            'resources' => [],
            'hits' => [],
        ];

        // A search result is an annotation on the canvas of the original item,
        // so an url managed by the iiif server.
        $iiifUrl = $this->getView()->plugin('iiifUrl');
        $baseResultUrl = $iiifUrl($this->item, 'iiifserver/uri', null, [
            'type' => 'annotation',
            'name' => 'search-result',
        ]) . '/';

        $baseCanvasUrl = $iiifUrl($this->item, 'iiifserver/uri', null, [
            'type' => 'canvas',
        ]) . '/p';

        // XPath’s string literals can’t contain both " and ' and doesn't manage
        // insensitive comparaison simply, so get all strings and preg them.

        $namespaces = $xml->getDocNamespaces();
        $altoNamespace = $namespaces['alto'] ?? $namespaces[''] ?? 'http://www.loc.gov/standards/alto/ns-v4#';
        $xml->registerXPathNamespace('alto', $altoNamespace);

        $resource = $this->item;
        try {
            // The hit index.
            $hit = 0;

            $index = -1;
            /** @var \SimpleXmlElement $xmlPage */
            foreach ($xml->Layout->Page as $xmlPage) {
                ++$index;
                $attributes = $xmlPage->attributes();
                // Skip empty pages.
                if (!$attributes->count()) {
                    continue;
                }
                // TODO The measure may not be pixel, but mm or inch. This may be managed by viewer.
                // TODO Check why casting to string is needed.
                // $page['number'] = (string) ((@$attributes->PHYSICAL_IMG_NR) + 1);
                $page['number'] = (string) ($index + 1);
                $page['width'] = (string) @$attributes->WIDTH;
                $page['height'] = (string) @$attributes->HEIGHT;
                if (!$page['width'] || !$page['height']) {
                    $this->logger->warn(sprintf(
                        'Incomplete data for xml file from item #%1$s, page %2$s.', // @translate
                        $this->firstXmlFile->item()->id(), $index
                    ));
                    continue;
                }

                $pageIndex = $index;
                // Should be the same than index.
                $pageIndex = $page['number'] - 1;
                if ($pageIndex !== $index) {
                    $this->logger->warn(sprintf(
                        'Inconsistent data for xml file from item #%1$s, page %2$s.', // @translate
                        $this->firstXmlFile->item()->id(), $index
                    ));
                    continue;
                }

                $hits = [];
                $hitMatches = [];

                $xmlPage->registerXPathNamespace('alto', $altoNamespace);

                foreach ($xmlPage->xpath('descendant::alto:String') as $xmlString) {
                    $attributes = $xmlString->attributes();
                    $matches = [];
                    $zone = [];
                    $zone['text'] = (string) $attributes->CONTENT;
                    foreach ($queryWords as $chars) {
                        if (!empty($this->imageSizes[$pageIndex]['width'])
                            && !empty($this->imageSizes[$pageIndex]['height'])
                            && preg_match('/' . $chars . '/Uui', $zone['text'], $matches) > 0
                        ) {
                            $zone['top'] = (string) @$attributes->VPOS;
                            $zone['left'] = (string) @$attributes->HPOS;
                            $zone['width'] = (string) @$attributes->WIDTH;
                            $zone['height'] = (string) @$attributes->HEIGHT;
                            if (!strlen($zone['top']) || !strlen($zone['left']) || !$zone['width'] || !$zone['height']) {
                                $this->logger->warn(sprintf(
                                    'Inconsistent data for xml file from item #%1$s, page %2$s.', // @translate
                                    $this->firstXmlFile->item()->id(), $index + 1
                                ));
                                continue;
                            }

                            ++$hit;

                            $image = $this->imageSizes[$pageIndex];
                            $searchResult = new AnnotationSearchResult;
                            $searchResult->initOptions(['baseResultUrl' => $baseResultUrl, 'baseCanvasUrl' => $baseCanvasUrl]);
                            $result['resources'][] = $searchResult->setResult(compact('resource', 'image', 'page', 'zone', 'chars', 'hit'));

                            $hits[] = $searchResult->id();
                            // TODO Get matches as whole world and all matches in last time (preg_match_all).
                            // TODO Get the text before first and last hit of the page.
                            $hitMatches[] = $matches[0];
                        }
                    }
                }

                // Add hits per page.
                if ($hits) {
                    $searchHit = new SearchHit;
                    $searchHit['annotations'] = $hits;
                    $searchHit['match'] = implode(' ', array_unique($hitMatches));
                    $result['hits'][] = $searchHit;
                }
            }
        } catch (\Exception $e) {
            $this->logger->err(sprintf(
                'Error: XML alto content may be invalid for item #%1$d, index #%2$d!', // @translate
                $this->firstXmlFile->item()->id(), $index + 1
            ));
            return null;
        }

        return $result;
    }

    protected function searchFullTextPdfXml(SimpleXmlElement $xml, $queryWords): ?array
    {
        $result = [
            'resources' => [],
            'hits' => [],
        ];

        // A search result is an annotation on the canvas of the original item,
        // so an url managed by the iiif server.
        $view = $this->getView();
        $baseResultUrl = $view->iiifUrl($this->item, 'iiifserver/uri', null, [
            'type' => 'annotation',
            'name' => 'search-result',
        ]) . '/';

        $baseCanvasUrl = $view->iiifUrl($this->item, 'iiifserver/uri', null, [
            'type' => 'canvas',
        ]) . '/p';

        $resource = $this->item;
        $matches = [];
        try {
            $hit = 0;
            $index = -1;
            foreach ($xml->page as $xmlPage) {
                ++$index;
                $attributes = $xmlPage->attributes();
                $page['number'] = (string) @$attributes->number;
                $page['width'] = (string) @$attributes->width;
                $page['height'] = (string) @$attributes->height;
                if (!strlen($page['number']) || !strlen($page['width']) || !strlen($page['height'])) {
                    $this->logger->warn(sprintf(
                        'Incomplete data for xml file from pdf media #%1$s, page %2$s.', // @translate
                        $this->firstXmlFile->id(), $index
                    ));
                    continue;
                }

                // Should be the same than index.
                $pageIndex = $page['number'] - 1;
                if ($pageIndex !== $index) {
                    $this->logger->warn(sprintf(
                        'Inconsistent data for xml file from pdf media #%1$s, page %2$s.', // @translate
                        $this->firstXmlFile->id(), $index
                    ));
                    continue;
                }

                $hits = [];
                $hitMatches = [];
                $rowIndex = -1;
                foreach ($xmlPage->text as $xmlRow) {
                    ++$rowIndex;
                    $zone = [];
                    $zone['text'] = strip_tags($xmlRow->asXML());
                    foreach ($queryWords as $chars) {
                        if (!empty($this->imageSizes[$pageIndex]['width'])
                            && !empty($this->imageSizes[$pageIndex]['height'])
                            && preg_match('/' . $chars . '/Uui', $zone['text'], $matches) > 0
                        ) {
                            $attributes = $xmlRow->attributes();
                            $zone['top'] = (string) @$attributes->top;
                            $zone['left'] = (string) @$attributes->left;
                            $zone['width'] = (string) @$attributes->width;
                            $zone['height'] = (string) @$attributes->height;
                            if (!strlen($zone['top']) || !strlen($zone['left']) || !$zone['width'] || !$zone['height']) {
                                $this->logger->warn(sprintf(
                                    'Inconsistent data for xml file from pdf media #%1$s, page %2$s, row %3$s.', // @translate
                                    $this->firstXmlFile->id(), $pageIndex, $rowIndex
                                ));
                                continue;
                            }

                            ++$hit;

                            $image = $this->imageSizes[$pageIndex];
                            $searchResult = new AnnotationSearchResult;
                            $searchResult->initOptions(['baseResultUrl' => $baseResultUrl, 'baseCanvasUrl' => $baseCanvasUrl]);
                            $result['resources'][] = $searchResult->setResult(compact('resource', 'image', 'page', 'zone', 'chars', 'hit'));

                            $hits[] = $searchResult->id();
                            // TODO Get matches as whole world and all matches in last time (preg_match_all).
                            // TODO Get the text before first and last hit of the page.
                            $hitMatches[] = $matches[0];
                        }
                    }
                }

                // Add hits per page.
                if ($hits) {
                    $searchHit = new SearchHit;
                    $searchHit['annotations'] = $hits;
                    $searchHit['match'] = implode(' ', array_unique($hitMatches));
                    $result['hits'][] = $searchHit;
                }
            }
        } catch (\Exception $e) {
            $this->logger->err(sprintf(
                'Error: PDF to XML conversion failed for media file #%d!', // @translate
                $this->firstXmlFile->id()
            ));
            return null;
        }

        return $result;
    }

    /**
     * Check if the item support search and init the xml files.
     *
     * There may be one xml for all pages (pdf2xml).
     * There may be one xml by page.
     * There may be missing alto to some images.
     * There may be only xml files in the items.
     * Alto allows one xml by page o rone xml for all pages too.
     *
     * So get the exact list matching images (if any) to avoid bad page indexes.
     *
     * The logic is managed by the module Iiif server if available: it manages
     * the same process for the text overlay.
     */
    protected function prepareSearch(): bool
    {
        $this->xmlFiles = [];
        $this->imageSizes = [];
        foreach ($this->item->media() as $media) {
            $mediaType = $media->mediaType();
            if (in_array($mediaType, $this->supportedMediaTypes)) {
                $this->xmlFiles[] = $media;
            } elseif ($mediaType === 'text/xml' || $mediaType === 'application/xml') {
                $this->logger->warn(
                    sprintf('Warning: Xml format "%1$s" of media #%2$d is not precise enough and is skipped.', // @translate
                        $this->mediaType, $media->id()
                    ));
            } else {
                // TODO The images sizes may be stored by xml files too, so skip size retrieving once the matching between images and text is done by page.
                $mediaData = $media->mediaData();
                // Iiif info stored by Omeka.
                if (isset($mediaData['width'])) {
                    $this->imageSizes[] = [
                        'width' => $mediaData['width'],
                        'height' => $mediaData['height'],
                    ];
                }
                // Info stored by Iiif Server.
                elseif (isset($mediaData['dimensions']['original']['width'])) {
                    $this->imageSizes[] = $mediaData['dimensions']['original'];
                } elseif ($media->hasOriginal() && strtok($mediaType, '/') === 'image') {
                    $this->imageSizes[] = $this->imageSize
                        ? $this->imageSize->__invoke($media, 'original')
                        : $this->imageSizeLocal($media);
                }
            }
        }

        $this->firstXmlFile = count($this->xmlFiles) ? reset($this->xmlFiles) : null;

        return $this->firstXmlFile
            && count($this->imageSizes);
    }

    protected function imageSizeLocal(MediaRepresentation $media): array
    {
        // Some media types don't save the file locally.
        $filepath = ($filename = $media->filename())
            ? $this->basePath . '/original/' . $filename
            : $media->originalUrl();
        $size = getimagesize($filepath);
        return $size
            ? ['width' => $size[0], 'height' => $size[1]]
            : ['width' => 0, 'height' => 0];
    }

    /**
     * Normalize query because the search occurs inside a normalized text.
     *
     * Don't query small words and quote them one time.
     *
     * The comparaison with strcasecmp() give bad results with unicode ocr, so
     * use preg_match().
     *
     * The same word can be set multiple times in the same query.
     */
    protected function formatQuery($query): array
    {
        $minimumQueryLength = $this->view->setting('iiifsearch_minimum_query_length') ?? $this->minimumQueryLength;

        $cleanQuery = $this->alnumString($query);
        if (mb_strlen($cleanQuery) < $minimumQueryLength) {
            return [];
        }

        $queryWords = explode(' ', $cleanQuery);
        if (count($queryWords) === 1) {
            return [preg_quote($queryWords[0], '/')];
        }

        $chars = [];
        foreach ($queryWords as $queryWord) {
            if (mb_strlen($queryWord) >= $minimumQueryLength) {
                $chars[] = preg_quote($queryWord, '/');
            }
        }
        if (count($chars) > 1) {
            $chars[] = preg_quote($queryWords, '/');
        }
        return $chars;
    }



    /**
     * @todo The format pdf2xml can be replaced by an alto multi-pages, even if the format is quicker for search, but less precise for positions.
     */
    protected function loadXml(): ?SimpleXMLElement
    {
        // The media type is already checked.
        $this->mediaType = $this->firstXmlFile->mediaType();

        if ($this->mediaType === 'application/alto+xml' && count($this->xmlFiles) > 1) {
            return $this->mergeXmlAlto();
        }

        $filepath = ($filename = $this->firstXmlFile->filename())
            ? $this->basePath . '/original/' . $filename
            : $this->firstXmlFile->originalUrl();

        $xmlContent = file_get_contents($filepath);

        if ($this->fixUtf8) {
            $xmlContent = $this->fixUtf8->__invoke($xmlContent);
        }

        if (!$xmlContent) {
            $this->logger->err(sprintf(
                'Error: XML content seems empty for media #%d!', // @translate
                $this->firstXmlFile->id()
            ));
            return null;
        }

        // Manage an exception.
        if ($this->mediaType === 'application/vnd.pdf2xml+xml') {
            $xmlContent = preg_replace('/\s{2,}/ui', ' ', $xmlContent);
            $xmlContent = preg_replace('/<\/?b>/ui', '', $xmlContent);
            $xmlContent = preg_replace('/<\/?i>/ui', '', $xmlContent);
            $xmlContent = str_replace('<!doctype pdf2xml system "pdf2xml.dtd">', '<!DOCTYPE pdf2xml SYSTEM "pdf2xml.dtd">', $xmlContent);
        }

        $xmlContent = simplexml_load_string($xmlContent);
        if (!$xmlContent) {
            $this->logger->err(sprintf(
                'Error: Cannot get XML content from media #%d!', // @translate
                $this->firstXmlFile->id()
            ));
            return null;
        }

        return $xmlContent;
    }

    /**
     * @todo Cache the merged alto files.
     *
     * @todo Use the alto page indexes if available (but generally via mets).
     */
    protected function mergeXmlAlto(): ?SimpleXMLElement
    {
        // Only alto is managed currently.
        if ($this->mediaType !== 'application/alto+xml') {
            return null;
        }

        // Merge all alto files into one.
        // This is a search engine with positions for strings, so only the
        // layout is needed. Get metadata from first file.

        /**
         * DOM is used because SimpleXml cannot add xml nodes (only strings).
         *
         * @var \SimpleXMLElement $alto
         * @var \SimpleXMLElement $altoLayout
         * @var \DOMElement $altoLayoutDom
         */
        $alto = null;
        $altoLayout = null;
        $altoLayoutDom = null;

        $first = true;
        foreach ($this->xmlFiles as $xmlFileMedia) {
            $filepath = ($filename = $xmlFileMedia->filename())
                ? $this->basePath . '/original/' . $filename
                : $xmlFileMedia->originalUrl();
            $xmlContent = file_get_contents($filepath);
            if ($this->fixUtf8) {
                $xmlContent = $this->fixUtf8->__invoke($xmlContent);
            }

            $currentXml = @simplexml_load_string($xmlContent);
            if (!$currentXml) {
                $this->logger->err(sprintf(
                    'Error: Cannot get XML content from media #%d!', // @translate
                    $xmlFileMedia->id()
                ));
                if (!$alto) {
                    return null;
                }
                // Insert an empty page to keep page indexes.
                $altoLayout->addChild('Page');
                continue;
            }

            if ($first) {
                $first = false;
                $alto = $currentXml;
                $altoLayout = $alto->Layout;
                if (!$altoLayout || !$altoLayout->count()) {
                    return null;
                }
                $altoLayoutDom = dom_import_simplexml($altoLayout);
                $currentXmlFirstPage = $altoLayout->Page;
                if (!$currentXmlFirstPage || !$currentXmlFirstPage->count()) {
                    $altoLayout->addChild('Page');
                }
                continue;
            }

            $currentXmlFirstPage = $currentXml->Layout->Page;
            if (!$currentXmlFirstPage || !$currentXmlFirstPage->count()) {
                $altoLayout->addChild('Page');
                continue;
            }

            $currentXmlDomPage = dom_import_simplexml($currentXmlFirstPage);
            $altoLayoutDom->appendChild($altoLayoutDom->ownerDocument->importNode($currentXmlDomPage, true));
        }

        return $alto;
    }

    /**
     * Returns a cleaned  string.
     *
     * Removes trailing spaces and anything else, except letters, numbers and
     * symbols.
     */
    protected function alnumString($string): string
    {
        $string = preg_replace('/[^\p{L}\p{N}\p{S}]/u', ' ', (string) $string);
        return trim(preg_replace('/\s+/', ' ', $string));
    }
}
