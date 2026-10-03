<?php
namespace TeamManager\Entity;

use DateTime;
use Doctrine\ORM\Mapping as ORM;

/**
 * An invitation (team -> user) or a join request (user -> team).
 *
 * The core GroupJoinRequests table is not used because its notification handling is broken in 9.4.
 *
 * @ORM\Entity()
 * @ORM\Table(name="tmTeamRequest", indexes={
 *     @ORM\Index(name="team_user_status", columns={"teamID", "uID", "status"}),
 *     @ORM\Index(name="user_status", columns={"uID", "status"})
 * })
 */
class TeamRequest
{
    const TYPE_INVITE = 'invite';
    const TYPE_JOIN = 'join';

    const STATUS_PENDING = 'pending';
    const STATUS_ACCEPTED = 'accepted';
    const STATUS_DECLINED = 'declined';
    const STATUS_CANCELLED = 'cancelled';

    /**
     * @ORM\Id
     * @ORM\Column(type="integer", options={"unsigned": true})
     * @ORM\GeneratedValue
     */
    protected $id;

    /**
     * @ORM\ManyToOne(targetEntity="TeamManager\Entity\Team")
     * @ORM\JoinColumn(name="teamID", referencedColumnName="id", nullable=false, onDelete="CASCADE")
     */
    protected $team;

    /**
     * The user that is invited or wants to join.
     *
     * @ORM\Column(type="integer", options={"unsigned": true})
     */
    protected $uID;

    /**
     * @ORM\Column(type="string", length=16)
     */
    protected $type;

    /**
     * @ORM\Column(type="string", length=16)
     */
    protected $status = self::STATUS_PENDING;

    /**
     * The user that created the request (inviting captain or the joining user).
     *
     * @ORM\Column(type="integer", options={"unsigned": true})
     */
    protected $createdBy;

    /**
     * The user that accepted, declined or cancelled the request.
     *
     * @ORM\Column(type="integer", options={"unsigned": true}, nullable=true)
     */
    protected $respondedBy;

    /**
     * @ORM\Column(type="datetime")
     */
    protected $createdAt;

    /**
     * @ORM\Column(type="datetime", nullable=true)
     */
    protected $respondedAt;

    public function __construct(Team $team, int $uID, string $type, int $createdBy)
    {
        $this->team = $team;
        $this->uID = $uID;
        $this->type = $type;
        $this->createdBy = $createdBy;
        $this->createdAt = new DateTime();
    }

    public function getID(): int
    {
        return (int) $this->id;
    }

    public function getTeam(): Team
    {
        return $this->team;
    }

    public function getTeamID(): int
    {
        return $this->team->getID();
    }

    public function getUserID(): int
    {
        return (int) $this->uID;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function isInvite(): bool
    {
        return $this->type === self::TYPE_INVITE;
    }

    public function isJoinRequest(): bool
    {
        return $this->type === self::TYPE_JOIN;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function getCreatedBy(): int
    {
        return (int) $this->createdBy;
    }

    public function getRespondedBy(): ?int
    {
        return $this->respondedBy === null ? null : (int) $this->respondedBy;
    }

    public function getCreatedAt(): DateTime
    {
        return $this->createdAt;
    }

    public function getRespondedAt(): ?DateTime
    {
        return $this->respondedAt;
    }

    public function resolve(string $status, int $respondedBy): self
    {
        $this->status = $status;
        $this->respondedBy = $respondedBy;
        $this->respondedAt = new DateTime();

        return $this;
    }
}
