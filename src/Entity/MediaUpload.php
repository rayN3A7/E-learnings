<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'mediaupload')]
class MediaUpload
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(type: 'string', length: 500)]
    private string $url;

    #[ORM\Column(type: 'string', length: 50, nullable: true)]
    private ?string $type = null;

    #[ORM\ManyToOne(targetEntity: User::class, inversedBy: 'mediaUploads')]
    #[ORM\JoinColumn(name: 'uploadedBy', referencedColumnName: 'id')]
    private ?User $uploadedBy = null;

    #[ORM\Column(type: 'datetime', nullable: true, name: 'uploadedAt')]
    private ?\DateTimeInterface $uploadedAt = null;

    #[ORM\ManyToOne(targetEntity: WrittenSection::class, inversedBy: 'mediaUploads')]
    #[ORM\JoinColumn(name: 'writtenSectionId', referencedColumnName: 'id', nullable: true)]
    private ?WrittenSection $writtenSection = null;

    #[ORM\ManyToOne(targetEntity: Comment::class, inversedBy: 'mediaUploads')]
    #[ORM\JoinColumn(name: 'commentId', referencedColumnName: 'id', nullable: true)]
    private ?Comment $comment = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUrl(): string
    {
        return $this->url;
    }

    public function setUrl(string $url): self
    {
        $this->url = $url;
        return $this;
    }

    public function getType(): ?string
    {
        return $this->type;
    }

    public function setType(?string $type): self
    {
        $this->type = $type;
        return $this;
    }

    public function getUploadedBy(): ?User
    {
        return $this->uploadedBy;
    }

    public function setUploadedBy(?User $uploadedBy): self
    {
        $this->uploadedBy = $uploadedBy;
        return $this;
    }

    public function getUploadedAt(): ?\DateTimeInterface
    {
        return $this->uploadedAt;
    }

    public function setUploadedAt(?\DateTimeInterface $uploadedAt): self
    {
        $this->uploadedAt = $uploadedAt;
        return $this;
    }

    public function getWrittenSection(): ?WrittenSection
    {
        return $this->writtenSection;
    }

    public function setWrittenSection(?WrittenSection $writtenSection): self
    {
        $this->writtenSection = $writtenSection;
        return $this;
    }

    public function getComment(): ?Comment
    {
        return $this->comment;
    }

    public function setComment(?Comment $comment): self
    {
        $this->comment = $comment;
        return $this;
    }
}