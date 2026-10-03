<?php
namespace TeamManager\Entity;

use Concrete\Core\User\UserInfo;
use Concrete\Core\User\UserInfoRepository;
use DateTime;
use Doctrine\ORM\Mapping as ORM;

/**
 * @ORM\Entity()
 * @ORM\Table(name="tmTeamMember",
 *     uniqueConstraints={@ORM\UniqueConstraint(name="team_user", columns={"teamID", "uID"})},
 *     indexes={@ORM\Index(name="user", columns={"uID"})}
 * )
 */
class TeamMember
{
    /**
     * @ORM\Id
     * @ORM\Column(type="integer", options={"unsigned": true})
     * @ORM\GeneratedValue
     */
    protected $id;

    /**
     * @ORM\ManyToOne(targetEntity="TeamManager\Entity\Team", inversedBy="members")
     * @ORM\JoinColumn(name="teamID", referencedColumnName="id", nullable=false, onDelete="CASCADE")
     */
    protected $team;

    /**
     * @ORM\Column(type="integer", options={"unsigned": true})
     */
    protected $uID;

    /**
     * @ORM\Column(type="boolean")
     */
    protected $captain = false;

    /**
     * @ORM\Column(type="datetime")
     */
    protected $joinedAt;

    /** @var UserInfo|null|false not persisted, false = not loaded yet */
    protected $userInfo = false;

    public function __construct(Team $team, int $uID, bool $captain = false)
    {
        $this->team = $team;
        $this->uID = $uID;
        $this->captain = $captain;
        $this->joinedAt = new DateTime();
    }

    public function getID(): int
    {
        return (int) $this->id;
    }

    public function getTeam(): Team
    {
        return $this->team;
    }

    public function getUserID(): int
    {
        return (int) $this->uID;
    }

    public function isCaptain(): bool
    {
        return (bool) $this->captain;
    }

    public function setCaptain(bool $captain): self
    {
        $this->captain = $captain;

        return $this;
    }

    public function getJoinedAt(): DateTime
    {
        return $this->joinedAt;
    }

    public function getUserInfo(): ?UserInfo
    {
        if ($this->userInfo === false) {
            $this->userInfo = app(UserInfoRepository::class)->getByID($this->getUserID()) ?: null;
        }

        return $this->userInfo;
    }

    public function getUserName(): string
    {
        $userInfo = $this->getUserInfo();

        return $userInfo ? (string) $userInfo->getUserName() : t('Deleted user');
    }
}
