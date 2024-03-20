<?php

namespace Datto\Display\Banner;

/**
 * Represents a banner to be displayed on a page to highlight updates,
 * warnings or errors to the user.
 *
 * @author Philipp Heckel <ph@datto.com>
 */
class Banner
{
    const TYPE_DEFAULT = 'banner-default';
    const TYPE_SUCCESS = 'banner-success';
    const TYPE_WARNING = 'banner-warning';
    const TYPE_DANGER = 'banner-danger';
    const TYPE_INFO = 'banner-info';
    const TYPE_SUCCESS_CLF = 'alert-success';
    const TYPE_WARNING_CLF = 'alert-warning';
    const TYPE_DANGER_CLF = 'alert-danger';
    const TYPE_INFO_CLF = 'alert-info';

    const CLOSE_LOCKED = 1;      // Do not allow this banner to be closed (don't show the close [x] icon)
    const CLOSE_SESSION = -1;    // Allow this banner to be closed and stay closed for the entire session

    /** @var string Unique ID for this banner */
    private $id;

    /** @var string */
    private $title;

    /** @var string */
    private $message;

    /** @var string */
    private $type;

    /** @var int */
    private $close;

    /** @var array */
    private $params;

    /**
     * @param string $id Unique identifier that will become part of the DOM id tag
     * @param string $title Title of the banner
     * @param string|string[]|null $message Additional message to display on the banner
     * @param string $type One of the Banner::TYPE_* constants above
     * @param int $close One of the Banner::CLOSE_* constants above
     * @param array $params Additional parameters
     */
    public function __construct(
        $id,
        $title,
        $message = null,
        $type = self::TYPE_DEFAULT,
        $close = self::CLOSE_SESSION,
        array $params = []
    ) {
        $this->id = $id;
        $this->title = $title;
        $this->message = $message;
        $this->type = $type;
        $this->close = $close;
        $this->params = $params;
    }

    /**
     * @return string
     */
    public function getId()
    {
        return $this->id;
    }

    /**
     * @return string
     */
    public function getTitle()
    {
        return $this->title;
    }

    /**
     * @return string
     */
    public function getMessage()
    {
        return $this->message;
    }

    /**
     * @return string
     */
    public function getType()
    {
        return $this->type;
    }

    /**
     * @return int
     */
    public function getClose()
    {
        return $this->close;
    }

    /**
     * @return boolean
     */
    public function isLocked()
    {
        return $this->close == self::CLOSE_LOCKED;
    }

    /**
     * @return array
     */
    public function getParams()
    {
        return $this->params;
    }

    /**
     * Gets the array to be passed to "BannerWidget.js" and "banners.html.twig"
     * @return array
     */
    public function toArray()
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'message' => $this->message,
            'type' => $this->type,
            'locked' => $this->isLocked(),
            'params' => $this->params
        ];
    }
}
