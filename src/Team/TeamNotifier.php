<?php
namespace TeamManager\Team;

use Concrete\Core\Application\Application;
use Concrete\Core\Config\Repository\Repository;
use Concrete\Core\Logging\Channels;
use Concrete\Core\Logging\LoggerFactory;
use Concrete\Core\User\UserInfo;
use TeamManager\Entity\Team;
use Throwable;

/**
 * Sends the team related emails. Mail failures are logged and never break the team action itself.
 */
class TeamNotifier
{
    /** @var Application */
    protected $app;
    /** @var Repository */
    protected $siteConfig;
    /** @var string|null page the links in emails point to */
    protected $link;

    public function __construct(Application $app, Repository $config)
    {
        $this->app = $app;
        $this->siteConfig = $config;
    }

    public function setLink(?string $link): void
    {
        $this->link = $link;
    }

    public function invite(Team $team, UserInfo $invitee, UserInfo $captain): void
    {
        $this->send('team_invite', $invitee, [
            'teamName' => $team->getDisplayName(),
            'fromName' => $captain->getUserName(),
        ]);
    }

    public function joinRequest(Team $team, UserInfo $requester): void
    {
        foreach ($team->getCaptains() as $captain) {
            if (!$captain->getUserInfo()) {
                continue;
            }
            $this->send('team_join_request', $captain->getUserInfo(), [
                'teamName' => $team->getDisplayName(),
                'fromName' => $requester->getUserName(),
            ]);
        }
    }

    /**
     * Tells the creator of a request (inviting captain or joining user) about the response.
     */
    public function requestAnswered(Team $team, UserInfo $recipient, UserInfo $responder, bool $isInvite, bool $accepted): void
    {
        $this->send('team_request_response', $recipient, [
            'teamName' => $team->getDisplayName(),
            'fromName' => $responder->getUserName(),
            'isInvite' => $isInvite,
            'accepted' => $accepted,
        ]);
    }

    protected function send(string $template, UserInfo $recipient, array $params): void
    {
        if (!$recipient->getUserEmail()) {
            return;
        }
        try {
            $mail = $this->app->make('mail');
            $mail->addParameter('recipientName', $recipient->getUserName());
            $mail->addParameter('siteName', tc('SiteName', $this->app->make('site')->getSite()->getSiteName()));
            $mail->addParameter('link', $this->link ?: (string) $this->app->make('url/canonical'));
            foreach ($params as $key => $value) {
                $mail->addParameter($key, $value);
            }
            $mail->from(
                $this->siteConfig->get('concrete.email.default.address'),
                $this->siteConfig->get('concrete.email.default.name')
            );
            $mail->to($recipient->getUserEmail(), $recipient->getUserName());
            $mail->load($template, 'team_manager');
            $mail->sendMail();
        } catch (Throwable $e) {
            $this->app->make(LoggerFactory::class)
                ->createLogger(Channels::CHANNEL_EXCEPTIONS)
                ->warning(t('Team Manager: unable to send "%s" mail: %s', $template, $e->getMessage()));
        }
    }
}
