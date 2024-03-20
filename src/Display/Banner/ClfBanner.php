<?php

namespace Datto\Display\Banner;

class ClfBanner
{
    public const TYPE_SUCCESS = 'success';
    public const TYPE_INFO = 'info';
    public const TYPE_WARNING = 'warning';
    public const TYPE_ERROR = 'error';

    private string $id = '';
    private string $type = '';
    private string $messageTitle = '';
    private string $messageText = '';
    private string $messageLinkText = '';
    private string $messageLinkUrl = '';
    private array $buttons = [];
    private bool $isDismissible = false;
    private bool $isSectionAlert = false;

    public function __construct(string $type, string $id)
    {
        $this->type = $type;
        $this->id = $id;
    }

    public function setId(string $id): self
    {
        $this->id = $id;
        return $this;
    }

    public function setType(string $type): self
    {
        $this->type = $type;
        return $this;
    }

    public function setMessageTitle(string $messageTitle): self
    {
        $this->messageTitle = $messageTitle;
        return $this;
    }

    public function setMessageText(string $messageText): self
    {
        $this->messageText = $messageText;
        return $this;
    }

    public function setMessageLink(string $text, string $url): self
    {
        $this->messageLinkText = $text;
        $this->messageLinkUrl = $url;
        return $this;
    }

    public function setIsDismissible(bool $isDismissible): self
    {
        $this->isDismissible = $isDismissible;
        return $this;
    }

    public function setIsSectionAlert(bool $isSectionAlert): self
    {
        $this->isSectionAlert = $isSectionAlert;
        return $this;
    }

    public function addButton(string $text, ?string $url = null, string $id = ''): self
    {
        $this->buttons[] = [
            'id' => $id,
            'url' => $url,
            'text' => $text,
        ];
        return $this;
    }

    public function addDataActionButton(string $text, string $dataAction = ''): self
    {
        $this->buttons[] = [
            'text' => $text,
            'dataAction' => $dataAction,
            'url' => '#'
        ];
        return $this;
    }

    public function toArray(): array
    {
        return [
            'type' => $this->type,
            'id' => $this->id,
            'message' => [
                'title' => $this->messageTitle,
                'text' => $this->messageText,
                'link' => [
                    'url' => $this->messageLinkUrl,
                    'text' => $this->messageLinkText,
                ],
            ],
            'buttons' => $this->buttons,
            'options' => [
                'dismissible' => $this->isDismissible,
                'sectionAlert' => $this->isSectionAlert
            ]
        ];
    }
}
