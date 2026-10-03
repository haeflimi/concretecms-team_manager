<?php
namespace TeamManager\Entity;

use DateTime;
use Doctrine\ORM\Mapping as ORM;

/**
 * A single user in a pool that is looking for a team ("free agent").
 * The entry is removed as soon as the user joins a team of the pool.
 *
 * @ORM\Entity()
 * @ORM\Table(name="tmTeamPoolUser", uniqueConstraints={@ORM\UniqueConstraint(name="pool_user", columns={"poolID", "uID"})})
 */
class TeamPoolUser
{
    /**
     * @ORM\Id
     * @ORM\Column(type="integer", options={"unsigned": true})
     * @ORM\GeneratedValue
     */
    protected $id;

    /**
     * @ORM\ManyToOne(targetEntity="TeamManager\Entity\TeamPool", inversedBy="singles")
     * @ORM\JoinColumn(name="poolID", referencedColumnName="gID", nullable=false, onDelete="CASCADE")
     */
    protected $pool;

    /**
     * @ORM\Column(type="integer", options={"unsigned": true})
     */
    protected $uID;

    /**
     * Short text shown to captains, e.g. preferred role.
     *
     * @ORM\Column(type="string", length=255, nullable=true)
     */
    protected $note;

    /**
     * @ORM\Column(type="datetime")
     */
    protected $joinedAt;

    public function __construct(TeamPool $pool, int $uID, ?string $note = null)
    {
        $this->pool = $pool;
        $this->uID = $uID;
        $this->note = $note === '' ? null : $note;
        $this->joinedAt = new DateTime();
    }

    public function getID(): int
    {
        return (int) $this->id;
    }

    public function getPool(): TeamPool
    {
        return $this->pool;
    }

    public function getUserID(): int
    {
        return (int) $this->uID;
    }

    public function getNote(): string
    {
        return (string) $this->note;
    }

    public function getJoinedAt(): DateTime
    {
        return $this->joinedAt;
    }
}
