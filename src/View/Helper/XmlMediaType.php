<?php declare(strict_types=1);

/**
 * @see XmlViewer
 */
namespace IiifSearch\View\Helper;

use finfo;
use Laminas\View\Helper\AbstractHelper;
use XMLReader;

/**
 * Get a more precise media type for xml files.
 *
 * @see \Omeka\File\TempFile
 * @see \BulkImport\Form\Reader\XmlReaderParamsForm
 * @see \ExtractText /data/media-types/media-type-identifiers
 * @see \IiifSearch /data/media-types/media-type-identifiers
 * @see \XmlViewer /data/media-types/media-type-identifiers
 */
class XmlMediaType extends AbstractHelper
{
    /**
     * @var string
     */
    protected $filepath;

    public function __invoke($filepath, $mediaType = null)
    {
        $this->filepath = $filepath;

        // The media type may be already properly detected.
        if (!$mediaType) {
            $mediaType = $this->simpleMediaType();
        }
        if ($mediaType === 'text/xml' || $mediaType === 'application/xml') {
            $mediaType = $this->getMediaTypeXml() ?: $mediaType;
        }
        if ($mediaType === 'application/zip') {
            $mediaType = $this->getMediaTypeZip() ?: $mediaType;
        }
        return $mediaType;
    }

    /**
     * Get the Internet media type of the file.
     *
     * @uses finfo
     * @return string
     */
    protected function simpleMediaType()
    {
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        return $finfo->file($this->filepath);
    }

    /**
     * Extract a more precise xml media type when possible.
     *
     * @return string
     */
    protected function getMediaTypeXml()
    {
        libxml_clear_errors();

        $reader = new XMLReader();
        if (!$reader->open($this->filepath)) {
            $message = new \Omeka\Stdlib\Message(
                'The file "%1$s" is not parsable by xml reader.', // @translate
                $this->filepath
            );
            $this->getView()->logger()->err($message);
            return null;
        }

        $type = null;

        // Don't output error in case of a badly formatted file since there is no logger.
        while (@$reader->read()) {
            if ($reader->nodeType === XMLReader::DOC_TYPE) {
                $type = $reader->name;
                break;
            }

            if ($reader->nodeType === XMLReader::PI
                && !in_array($reader->name, ['xml-stylesheet', 'oxygen'])
            ) {
                $matches = [];
                if (preg_match('~href="(.+?)"~mi', $reader->value, $matches)) {
                    $type = $matches[1];
                    break;
                }
            }

            if ($reader->nodeType === XMLReader::ELEMENT) {
                if ($reader->namespaceURI === 'urn:oasis:names:tc:opendocument:xmlns:office:1.0') {
                    $type = $reader->getAttributeNs('mimetype', 'urn:oasis:names:tc:opendocument:xmlns:office:1.0');
                } else {
                    $type = $reader->namespaceURI ?: $reader->getAttribute('xmlns');
                }
                if (!$type) {
                    $type = $reader->name;
                }
                break;
            }
        }

        $reader->close();

        $error = libxml_get_last_error();
        if ($error) {
            // TODO See module Next and use PsrMessage.
            $message = new \Omeka\Stdlib\Message(
                'Error level {%1$s}, code {%2$s}, for file "{%3$s}", line {%4$s}, column {%5$s}: {%6$s}', // @translate
                $error->level, $error->code, $error->file, $error->line, $error->column, $error->message
            );
            $this->getView()->logger()->err($message);
        }

        $xmlMediaTypes = require_once dirname(__DIR__, 3) . '/data/media-types/media-type-identifiers.php';

        return $xmlMediaTypes[$type] ?? null;
    }

    /**
     * Extract a more precise zipped media type when possible.
     *
     * In many cases, the media type is saved in a uncompressed file "mimetype"
     * at the beginning of the zip file. If present, get it.
     *
     * @return string
     */
    protected function getMediaTypeZip()
    {
        $handle = fopen($this->filepath, 'rb');
        $contents = fread($handle, 256);
        fclose($handle);
        return substr($contents, 30, 8) === 'mimetype'
            ? substr($contents, 38, strpos($contents, 'PK', 38) - 38)
            : null;
    }
}
