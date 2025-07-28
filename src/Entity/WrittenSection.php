<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;

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

    #[ORM\OneToMany(targetEntity: MediaUpload::class, mappedBy: 'writtenSection', cascade: ['persist', 'remove'])]
    private Collection $mediaUploads;

    #[ORM\OneToOne(targetEntity: Part::class, inversedBy: 'writtenSection')]
    #[ORM\JoinColumn(name: 'partId', referencedColumnName: 'id')]
    private ?Part $part = null;

    public function __construct()
    {
        $this->mediaUploads = new ArrayCollection();
    }

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

    public function getMediaUploads(): Collection
    {
        return $this->mediaUploads;
    }

    public function addMediaUpload(MediaUpload $mediaUpload): self
    {
        if (!$this->mediaUploads->contains($mediaUpload)) {
            $mediaUpload->setWrittenSection($this);
            $this->mediaUploads->add($mediaUpload);
        }
        return $this;
    }

    public function removeMediaUpload(MediaUpload $mediaUpload): self
    {
        if ($this->mediaUploads->removeElement($mediaUpload)) {
            $mediaUpload->setWrittenSection(null);
        }
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