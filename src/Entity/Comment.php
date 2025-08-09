<?php

namespace App\Entity;

use App\Repository\CommentRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: CommentRepository::class)]
#[ORM\Table(name: 'comment')]
class Comment
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'userId', referencedColumnName: 'id')]
    private ?User $user = null;

    #[ORM\ManyToOne(targetEntity: Part::class)]
    #[ORM\JoinColumn(name: 'partId', referencedColumnName: 'id')]
    private ?Part $part = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $content = null;

    #[ORM\Column(type: 'datetime', nullable: true, name: 'postedAt')]
    private ?\DateTimeInterface $postedAt = null;

    // NEW: For threaded replies
    #[ORM\ManyToOne(targetEntity: Comment::class, inversedBy: 'replies')]
    #[ORM\JoinColumn(name: 'parentId', referencedColumnName: 'id', nullable: true)]
    private ?Comment $parent = null;

    #[ORM\OneToMany(mappedBy: 'parent', targetEntity: Comment::class, orphanRemoval: true)]
    #[ORM\OrderBy(['postedAt' => 'ASC'])]
    private Collection $replies;

    #[ORM\ManyToOne(targetEntity: Course::class)]
    #[ORM\JoinColumn(name: 'courseId', referencedColumnName: 'id', nullable: true)]
    private ?Course $course = null;

    #[ORM\OneToMany(mappedBy: 'comment', targetEntity: CommentLike::class, cascade: ['persist', 'remove'])]
    private Collection $likes;

    #[ORM\OneToMany(mappedBy: 'comment', targetEntity: MediaUpload::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $mediaUploads;

    public function __construct()
    {
        $this->replies = new ArrayCollection();
        $this->likes = new ArrayCollection();
        $this->mediaUploads = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUser(): ?User
    {
        return $this->user;
    }

    public function setUser(?User $user): self
    {
        $this->user = $user;
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

    public function getContent(): ?string
    {
        return $this->content;
    }

    public function setContent(?string $content): self
    {
        $this->content = $content;
        return $this;
    }

    public function getPostedAt(): ?\DateTimeInterface
    {
        return $this->postedAt;
    }

    public function setPostedAt(?\DateTimeInterface $postedAt): self
    {
        $this->postedAt = $postedAt;
        return $this;
    }

    public function getParent(): ?self
    {
        return $this->parent;
    }

    public function setParent(?self $parent): self
    {
        $this->parent = $parent;
        return $this;
    }

    /**
     * @return Collection<int, self>
     */
    public function getReplies(): Collection
    {
        return $this->replies;
    }

    public function addReply(self $reply): self
    {
        if (!$this->replies->contains($reply)) {
            $this->replies->add($reply);
            $reply->setParent($this);
        }
        return $this;
    }

    public function removeReply(self $reply): self
    {
        if ($this->replies->removeElement($reply)) {
            if ($reply->getParent() === $this) {
                $reply->setParent(null);
            }
        }
        return $this;
    }

    public function getCourse(): ?Course
    {
        return $this->course;
    }

    public function setCourse(?Course $course): self
    {
        $this->course = $course;
        return $this;
    }

    public function getLikes(): Collection
    {
        return $this->likes;
    }

    public function addLike(CommentLike $like): self
    {
        if (!$this->likes->contains($like)) {
            $this->likes->add($like);
            $like->setComment($this);
        }
        return $this;
    }

    public function removeLike(CommentLike $like): self
    {
        if ($this->likes->removeElement($like)) {
            if ($like->getComment() === $this) {
                $like->setComment(null);
            }
        }
        return $this;
    }

    public function getLikeCount(): int
    {
        return $this->likes->count();
    }

    public function isLikedByUser(?User $user): bool
    {
        if (!$user) {
            return false;
        }
        return $this->likes->exists(function ($key, $like) use ($user) {
            return $like->getUser() === $user;
        });
    }

    public function getMediaUploads(): Collection
    {
        return $this->mediaUploads;
    }

    public function addMediaUpload(MediaUpload $mediaUpload): self
    {
        if (!$this->mediaUploads->contains($mediaUpload)) {
            $this->mediaUploads->add($mediaUpload);
            $mediaUpload->setComment($this);
        }
        return $this;
    }

    public function removeMediaUpload(MediaUpload $mediaUpload): self
    {
        if ($this->mediaUploads->removeElement($mediaUpload)) {
            if ($mediaUpload->getComment() === $this) {
                $mediaUpload->setComment(null);
            }
        }
        return $this;
    }
}