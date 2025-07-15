<?php

namespace App\Entity;

use App\Repository\WrittenSectionRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: WrittenSectionRepository::class)]
#[ORM\Table(name: 'writtensection')]
class WrittenSection
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $content = null;

    #[ORM\Column(type: 'json', nullable: true, name: 'mediaUrls')] 
    private ?array $mediaUrls = null;
    #[ORM\OneToOne(inversedBy: 'writtenSection', targetEntity: Part::class)]
    #[ORM\JoinColumn(name: 'partId', nullable: true)]
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