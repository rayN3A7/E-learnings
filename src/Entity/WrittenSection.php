<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'writtensection')]
class WrittenSection
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $content = null;

    #[ORM\Column(type: 'json', nullable: true, name: 'mediaUrls')]
private ?array $mediaUrls = [];

    #[ORM\OneToOne(targetEntity: Part::class, inversedBy: 'writtenSection')]
    #[ORM\JoinColumn(name: 'partId', referencedColumnName: 'id')]
    private ?Part $part = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getContent(): ?string
    {
        return $this->content;
    }

    public function setContent(?string $content): self
    {
        $this->content = $content;
        return $this;
    }

    public function getMediaUrls(): ?array
    {
        return $this->mediaUrls;
    }

    public function setMediaUrls(?array $mediaUrls): self
    {
        $this->mediaUrls = $mediaUrls;
        return $this;
    }

    public function getPart(): ?Part
    {
        return $this->part;
    }

    public function setPart(?Part $part): self
    {
        $this->part = $part;
        return $this;
    }
}