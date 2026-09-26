<?php
/**
 * @module Sharing
 */
/**
 * Notices: the delivered half of an engagement's lifecycle.
 *
 * Posting a lifecycle message on the engagement stream is an audit trail and
 * a socket update, not a notification: Streams delivers email, SMS and push
 * from Streams_Stream.notifyParticipants in the node process, which requires
 * a Streams_Subscription row, and nobody subscribes to an engagement
 * (ro#769). Rather than subscribe both parties to it -- the actor-skip in
 * notifyParticipants is commented out, so each party would be notified of
 * their own actions, and a subscription filter cannot express "only when the
 * other party did it" -- each user gets one notice stream they publish and
 * subscribe to, and the counterparty's notice is posted there. This is the
 * shape the host app already runs for class reservations.
 *
 * The copy is in text/Sharing/content/en.json, which must stay strict JSON
 * with no comments: the browser reads the same file with JSON.parse
 * (Q.Text.get), and one comment there breaks every string the client asks
 * for -- the composer does not open (ro#586). So the notes live here. Its
 * `notifications/<message type>` subjects are looked up per message type by
 * Streams_Message.deliver, in the RECIPIENT's language, and rendered as
 * handlebars against the notice's instructions, so each {{...}} is a field
 * instructions() puts there. Its `notifications/body/<verb>` sentences,
 * with an offer/need pair where the directions read differently, are
 * resolved when the notice is posted, not per recipient: the plugin's one
 * piece of copy that does not follow the reader's language (ro#769).
 *
 * @class Sharing_Notice
 */
class Sharing_Notice
{
	const TYPE = 'Sharing/notices';
	/**
	 * One per user, with a fixed name: Streams::subscribe() reads the
	 * subscribe rule out of the userStreams tree by stream NAME, so this
	 * cannot be a generated name.
	 */
	const NAME = 'Sharing/notices';

	/**
	 * Notify whichever party did not act.
	 *
	 * The two parties are the listing's publisher and the engagement's; the
	 * actor is excluded, which gives the right recipient for every event
	 * without a per-verb table: propose (by the responder) reaches the
	 * listing publisher, accept/reject/handOver/return/complete (by the
	 * publisher) reach the responder, and cancel or start, which either may
	 * post, reach the other one. forceCancel passes the closing publisher as
	 * the actor, so it reaches the responder.
	 *
	 * Never throws: a lifecycle transition that validated must not be undone
	 * because a notice could not be written.
	 *
	 * @method send
	 * @static
	 * @param {string} $byUserId the actor
	 * @param {Streams_Stream} $engagement
	 * @param {Streams_Stream} $listing
	 * @param {string} $messageType one of Sharing_Engagement::$messages
	 * @return {array} the user ids notified
	 */
	static function send($byUserId, $engagement, $listing, $messageType)
	{
		$notified = array();
		try {
			$recipients = self::recipients(
				$byUserId, $listing->publisherId, $engagement->publisherId
			);
			// The plugin's write scope: a notice stream is created, and a
			// Sharing/* message posted on it, only from here (Sharing_Guard).
			Sharing::asServer(function () use (
				$recipients, $byUserId, $engagement, $listing, $messageType, &$notified
			) {
				foreach ($recipients as $userId) {
					$stream = self::stream($userId);
					if (!$stream) {
						continue;
					}
					$stream->post(self::postedBy(), array(
						'type' => $messageType,
						'instructions' => self::instructions(
							$byUserId, $userId, $engagement, $listing, $messageType
						)
					), true);
					$notified[] = $userId;
				}
			});
		} catch (Exception $e) {
			Q::log("Sharing_Notice::send: " . $e->getMessage());
		}
		return $notified;
	}

	/**
	 * Who the notice is posted by: the community, never the actor, so that
	 * notifyParticipants can never read it as the recipient's own action.
	 * Falls back to the app's main community because currentCommunityId()
	 * needs a request and a transition can also come from a script.
	 * @method postedBy
	 * @static
	 * @protected
	 */
	protected static function postedBy()
	{
		try {
			return Sharing::communityId();
		} catch (Exception $e) {
			return Users::communityId();
		}
	}

	/**
	 * Whoever did not act, of the two parties: a pure function so the rule
	 * can be pinned without a database.
	 *
	 * @method recipients
	 * @static
	 * @param {string} $byUserId the actor
	 * @param {string} $listingPublisherId
	 * @param {string} $engagementPublisherId
	 * @return {array} user ids, each at most once
	 */
	static function recipients($byUserId, $listingPublisherId, $engagementPublisherId)
	{
		$result = array();
		foreach (array($listingPublisherId, $engagementPublisherId) as $userId) {
			if (!$userId or $userId === $byUserId or in_array($userId, $result, true)) {
				continue;
			}
			$result[] = $userId;
		}
		return $result;
	}

	/**
	 * The fields the subject and the notice template render from. Instructions
	 * are merged into the template's fields by Streams_Message.deliver, so
	 * everything the copy needs is here.
	 * @method instructions
	 * @static
	 * @protected
	 */
	protected static function instructions($byUserId, $toUserId, $engagement, $listing, $messageType)
	{
		$verb = array_search($messageType, Sharing_Engagement::$messages, true);
		$direction = $listing->getAttribute('direction');
		$text = Q_Text::get('Sharing/content');
		$displayName = Streams::displayName($byUserId, array(
			'short' => true, 'asUserId' => $toUserId
		));
		return array(
			// The actor's name as the RECIPIENT may see it: the notice is
			// posted by the community (so it is never the recipient's own
			// action), which leaves no avatar for node to resolve, and
			// asUserId keeps this inside the community's name-visibility
			// policy instead of the actor's own view of themselves.
			'displayName' => $displayName,
			'listingTitle' => $listing->title,
			'listingDirection' => $direction,
			'state' => $engagement->getAttribute('persistedState'),
			'verb' => $verb,
			// Pre-rendered because handlebars has no text-file lookup: one
			// notice template serves all eight events, and the copy stays in
			// text/Sharing/content with the rest of the plugin's. Subjects
			// come from config per message type, so those still resolve in
			// the recipient's language; this sentence does not (ro#769).
			'summary' => self::summary($text, $verb, $direction, $displayName),
			// The template's other strings: its fields are exactly these
			// instructions, so it has no way to read a text file itself.
			'cta' => (string)Q::ifset($text, 'notifications', 'View', 'See the details'),
			'why' => (string)Q::ifset($text, 'notifications', 'Why', ''),
			// Only on the first message: afterwards both parties have it, and
			// echoing a responder's own words back at them reads oddly.
			'note' => $messageType === Sharing_Engagement::$messages['propose']
				? (string)$engagement->getAttribute('note')
				: '',
			// One template for all eight events (Streams_Message.deliver
			// prefers instructions.templateName over the message type).
			'templateName' => 'Sharing/notice',
			'url' => Sharing_Engagement::url($engagement->publisherId, $engagement->name)
		);
	}

	/**
	 * The one event-specific sentence, by verb and listing direction.
	 * @method summary
	 * @static
	 * @protected
	 */
	protected static function summary($text, $verb, $direction, $displayName)
	{
		$bodies = Q::ifset($text, 'notifications', 'body', array());
		$body = Q::ifset($bodies, $verb, '');
		if (is_array($body)) {
			$body = Q::ifset($body, $direction, reset($body));
		}
		// Interpolated here, not in the template: the template renders its
		// fields, and a field's own {{...}} is never rendered again.
		return Q::interpolate((string)$body, compact('displayName'));
	}

	/**
	 * A user's notice stream, created and subscribed on first use.
	 *
	 * Subscribing only at creation is deliberate: a member who unsubscribes
	 * stays unsubscribed instead of being re-subscribed by the next event.
	 *
	 * @method stream
	 * @static
	 * @param {string} $userId
	 * @return {Streams_Stream|null}
	 */
	static function stream($userId)
	{
		$stream = Streams_Stream::fetch($userId, $userId, self::NAME);
		if ($stream) {
			return $stream;
		}
		return Sharing::asServer(function () use ($userId) {
			$stream = Streams::create($userId, $userId, self::TYPE, array(
				'name' => self::NAME
			), array('skipAccess' => true));
			// The stream object, not its name: it was just created, so this
			// skips a refetch, and _getStreams() takes either.
			Streams::subscribe($userId, $userId, $stream, array(
				'skipAccess' => true,
				'skipMessage' => true
			));
			return $stream;
		});
	}
}
