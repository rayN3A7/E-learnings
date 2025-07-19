<?php

namespace App\Entity;

use App\Repository\WrittenSectionRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * @method setMediaUrls(?array $mediaUrls)
 */
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

    /**
     * @return array|null
     */
    public function getMediaUrls(): ?array
    {
        // Ensure mediaUrls is always an array or null after decoding
        if ($this->mediaUrls === null) {
            return null;
        }
        return $this->mediaUrls;
    }

    /**
     * @param array|null $mediaUrls
     */
    public function setMediaUrls(?array $mediaUrls): self
    {
        $this->mediaUrls = $mediaUrls;
        return $this;
    }

    /**
     * @return array|null Decoded media URLs if available
     */
    public function getDecodedMediaUrls(): ?array
    {
        $rawMediaUrls = $this->getMediaUrls();
        if ($rawMediaUrls === null) {
            return null;
        }
        return is_array($rawMediaUrls) ? $rawMediaUrls : [];
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