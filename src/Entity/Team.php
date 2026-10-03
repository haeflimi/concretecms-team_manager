<?php
namespace TeamManager\Entity;

use Concrete\Core\Entity\File\File;
use DateTime;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * A team. Teams are stored in the package tables only, they are not core groups,
 * so they never show up in group memberships or permissions.
 *
 * @ORM\Entity()
 * @ORM\Table(name="tmTeam", uniqueConstraints={
 *     @ORM\UniqueConstraint(name="name", columns={"name"}),
 *     @ORM\UniqueConstraint(name="tag", columns={"tag"})
 * })
 */
class Team
{
    /**
     * @ORM\Id
     * @ORM\Column(type="integer", options={"unsigned": true})
     * @ORM\GeneratedValue
     */
    protected $id;

    /**
     * @ORM\Column(type="string", length=64)
     */
    protected $name;

    /**
     * @ORM\Column(type="string", length=16, nullable=true)
     */
    protected $tag;

    /**
     * @ORM\Column(type="text", nullable=true)
     */
    protected $description;

    /**
     * @ORM\Column(type="integer", options={"unsigned": true}, nullable=true)
     */
    protected $logoFileID;

    /**
     * @ORM\ManyToOne(targetEntity="TeamManager\Entity\TeamPool", inversedBy="teams")
     * @ORM\JoinColumn(name="poolID", referencedColumnName="gID", nullable=true, onDelete="SET NULL")
     */
    protected $pool;

    /**
     * @ORM\OneToMany(targetEntity="TeamManager\Entity\TeamMember", mappedBy="team", cascade={"persist", "remove"}, orphanRemoval=true)
     * @ORM\OrderBy({"joinedAt" = "ASC"})
     */
    protected $members;

    /**
     * @ORM\Column(type="integer", options={"unsigned": true})
     */
    protected $createdBy;

    /**
     * @ORM\Column(type="datetime")
     */
    protected $createdAt;

    public function __construct(string $name, int $createdBy)
    {
        $this->name = $name;
        $this->createdBy = $createdBy;
        $this->createdAt = new DateTime();
        $this->members = new ArrayCollection();
    }

    public function getID(): int
    {
        return (int) $this->id;
    }

    public function getName(): string
    {
        return (string) $this->name;
    }

    public function setName(string $name): self
    {
        $this->name = $name;

        return $this;
    }

    public function getDisplayName(): string
    {
        $tag = $this->getTag();

        return $tag ? sprintf('[%s] %s', $tag, $this->getName()) : $this->getName();
    }

    public function getTag(): ?string
    {
        return $this->tag;
    }

    public function setTag(?string $tag): self
    {
        $this->tag = $tag === '' ? null : $tag;

        return $this;
    }

    public function getDescription(): string
    {
        return (string) $this->description;
    }

    public function setDescription(?string $description): self
    {
        $this->description = $description === '' ? null : $description;

        return $this;
    }

    public function getLogoFileID(): ?int
    {
        return $this->logoFileID ? (int) $this->logoFileID : null;
    }

    public function setLogoFileID(?int $fID): self
    {
        $this->logoFileID = $fID ?: null;

        return $this;
    }

    public function getLogo(): ?File
    {
        if (!$this->logoFileID) {
            return null;
        }
        $file = \Concrete\Core\File\File::getByID($this->logoFileID);

        return $file instanceof File ? $file : null;
    }

    public function getPool(): ?TeamPool
    {
        return $this->pool;
    }

    public function setPool(?TeamPool $pool): self
    {
        $this->pool = $pool;

        return $this;
    }

    public function getCreatedBy(): int
    {
        return (int) $this->createdBy;
    }

    public function getCreatedAt(): DateTime
    {
        return $this->createdAt;
    }

    /**
     * @return TeamMember[] captains first, then by join date
     */
    public function getMembers(): array
    {
        $members = $this->members->toArray();
        usort($members, function (TeamMember $a, TeamMember $b) {
            if ($a->isCaptain() !== $b->isCaptain()) {
                return $a->isCaptain() ? -1 : 1;
            }

            return $a->getJoinedAt() <=> $b->getJoinedAt();
        });

        return $members;
    }

    /**
     * @return Collection|TeamMember[] unsorted, for adding / removing members
     */
    public function getMemberCollection(): Collection
    {
        return $this->members;
    }

    public function getMemberCount(): int
    {
        return $this->members->count();
    }

    public function getMember(int $uID): ?TeamMember
    {
        foreach ($this->members as $member) {
            if ($member->getUserID() === $uID) {
                return $member;
            }
        }

        return null;
    }

    public function hasMember(int $uID): bool
    {
        return $this->getMember($uID) !== null;
    }

    public function isCaptain(int $uID): bool
    {
        $member = $this->getMember($uID);

        return $member !== null && $member->isCaptain();
    }

    /**
     * @return TeamMember[]
     */
    public function getCaptains(): array
    {
        return array_values(array_filter($this->getMembers(), function (TeamMember $member) {
            return $member->isCaptain();
        }));
    }
}
