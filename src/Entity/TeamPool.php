<?php
namespace TeamManager\Entity;

use Concrete\Core\User\Group\Group;
use Concrete\Core\User\Group\GroupRepository;
use DateTime;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * Settings of a team pool. The pool itself is a core group of the "Team Pool" group type (same ID),
 * its members are the pool's team players and free agents, so pools can be used for permissions.
 *
 * @ORM\Entity()
 * @ORM\Table(name="tmTeamPool")
 */
class TeamPool
{
    /**
     * ID of the core group.
     *
     * @ORM\Id
     * @ORM\Column(type="integer", options={"unsigned": true})
     */
    protected $gID;

    /**
     * Users and captains may join / register at all.
     *
     * @ORM\Column(type="boolean")
     */
    protected $open = true;

    /**
     * Captains may register their teams themselves.
     *
     * @ORM\Column(type="boolean")
     */
    protected $allowTeams = true;

    /**
     * Users may create new teams in the pool themselves (My Teams block).
     *
     * @ORM\Column(type="boolean", options={"default": false})
     */
    protected $allowTeamCreation = false;

    /**
     * Users may join as free agent looking for a team.
     *
     * @ORM\Column(type="boolean")
     */
    protected $allowSingles = true;

    /**
     * Maximum number of teams, 0 = unlimited.
     *
     * @ORM\Column(type="integer", options={"unsigned": true})
     */
    protected $maxTeams = 0;

    /**
     * Maximum number of members per team of the pool, 0 = the global max_team_size applies.
     *
     * @ORM\Column(type="integer", options={"unsigned": true, "default": 0})
     */
    protected $maxTeamSize = 0;

    /**
     * @ORM\Column(type="datetime")
     */
    protected $createdAt;

    /**
     * @ORM\OneToMany(targetEntity="TeamManager\Entity\Team", mappedBy="pool")
     * @ORM\OrderBy({"name" = "ASC"})
     */
    protected $teams;

    /**
     * @ORM\OneToMany(targetEntity="TeamManager\Entity\TeamPoolUser", mappedBy="pool", cascade={"remove"}, orphanRemoval=true)
     * @ORM\OrderBy({"joinedAt" = "ASC"})
     */
    protected $singles;

    /** @var Group|null|false not persisted, false = not loaded yet */
    protected $group = false;

    public function __construct(int $gID)
    {
        $this->gID = $gID;
        $this->createdAt = new DateTime();
        $this->teams = new ArrayCollection();
        $this->singles = new ArrayCollection();
    }

    /**
     * The pool ID is the ID of its core group.
     */
    public function getID(): int
    {
        return (int) $this->gID;
    }

    public function getGroup(): ?Group
    {
        if ($this->group === false) {
            $this->group = app(GroupRepository::class)->getGroupById($this->getID()) ?: null;
        }

        return $this->group;
    }

    public function getName(): string
    {
        $group = $this->getGroup();

        return $group ? (string) $group->getGroupName() : t('Deleted pool');
    }

    public function getDescription(): string
    {
        $group = $this->getGroup();

        return $group ? (string) $group->getGroupDescription() : '';
    }

    public function isOpen(): bool
    {
        return (bool) $this->open;
    }

    public function setOpen(bool $open): self
    {
        $this->open = $open;

        return $this;
    }

    public function allowsTeams(): bool
    {
        return (bool) $this->allowTeams;
    }

    public function setAllowTeams(bool $allowTeams): self
    {
        $this->allowTeams = $allowTeams;

        return $this;
    }

    public function allowsTeamCreation(): bool
    {
        return (bool) $this->allowTeamCreation;
    }

    public function setAllowTeamCreation(bool $allowTeamCreation): self
    {
        $this->allowTeamCreation = $allowTeamCreation;

        return $this;
    }

    public function allowsSingles(): bool
    {
        return (bool) $this->allowSingles;
    }

    public function setAllowSingles(bool $allowSingles): self
    {
        $this->allowSingles = $allowSingles;

        return $this;
    }

    public function getMaxTeams(): int
    {
        return (int) $this->maxTeams;
    }

    public function setMaxTeams(int $maxTeams): self
    {
        $this->maxTeams = max(0, $maxTeams);

        return $this;
    }

    public function getMaxTeamSize(): int
    {
        return (int) $this->maxTeamSize;
    }

    public function setMaxTeamSize(int $maxTeamSize): self
    {
        $this->maxTeamSize = max(0, $maxTeamSize);

        return $this;
    }

    public function getCreatedAt(): DateTime
    {
        return $this->createdAt;
    }

    /**
     * @return Collection|Team[]
     */
    public function getTeams(): Collection
    {
        return $this->teams;
    }

    /**
     * @return Collection|TeamPoolUser[]
     */
    public function getSingles(): Collection
    {
        return $this->singles;
    }
}
