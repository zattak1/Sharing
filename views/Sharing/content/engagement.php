<?php
/**
 * One engagement. Variables: $engagement, $listing (export or null), $role,
 * $allowed, $user.
 */
$text = Q_Text::get('Sharing/content');
$t = $text['engagement'];
$e = $engagement;
?>
<div class="Sharing_engagement Sharing_state_<?php echo $e['state'] ?> Sharing_role_<?php echo $role ?: 'none' ?>"
     data-publisherId="<?php echo Q_Html::text($e['publisherId']) ?>"
     data-engagementId="<?php echo Q_Html::text($e['id']) ?>">
	<?php if ($e['listingUrl']): ?>
		<a class="Sharing_back" href="<?php echo Q_Html::text($e['listingUrl']) ?>">&larr; <?php echo Q_Html::text($e['title']) ?></a>
	<?php endif ?>
	<h1 class="Sharing_engagement_title">
		<?php echo Q_Html::text($listing ? $t['heading'][$listing['direction']] : $t['Heading']) ?>
	</h1>
	<div class="Sharing_engagement_parties">
		<span class="Sharing_engagement_responder"><?php echo Q::tool('Users/avatar', array(
			'userId' => $e['publisherId'], 'icon' => 50, 'short' => true
		), array('id' => 'engagement_responder')) ?></span>
		<?php if ($listing): ?>
			<span class="Sharing_engagement_arrow">&harr;</span>
			<span class="Sharing_engagement_publisher"><?php echo Q::tool('Users/avatar', array(
				'userId' => $listing['publisherId'], 'icon' => 50, 'short' => true
			), array('id' => 'engagement_publisher')) ?></span>
		<?php endif ?>
	</div>
	<p class="Sharing_engagement_state">
		<span class="Sharing_state_chip"><?php echo Q_Html::text($t['state'][$e['state']]) ?></span>
		<?php if ($e['quantity'] > 1): ?>&times; <?php echo (int)$e['quantity'] ?><?php endif ?>
	</p>
	<?php if ($e['note'] !== ''): ?>
		<blockquote class="Sharing_engagement_note"><?php echo nl2br(Q_Html::text($e['note'])) ?></blockquote>
	<?php endif ?>
	<?php if (!empty($allowed)): ?>
		<div class="Sharing_engagement_actions">
			<?php foreach ($allowed as $verb => $to): ?>
				<button class="Q_button Sharing_transition_button Sharing_verb_<?php echo $verb ?>"
					data-transition="<?php echo $verb ?>"><?php echo Q_Html::text($t['verb'][$verb]) ?></button>
			<?php endforeach ?>
		</div>
		<div class="Sharing_transition_error" hidden></div>
	<?php endif ?>
	<?php if ($role === 'responder' && !empty($e['private']['instructions'])): ?>
		<div class="Sharing_engagement_private">
			<h3><?php echo Q_Html::text($t['PrivateHeading']) ?></h3>
			<p><?php echo nl2br(Q_Html::text($e['private']['instructions'])) ?></p>
		</div>
	<?php elseif ($role === 'responder' && $e['state'] === 'proposed'): ?>
		<p class="Sharing_muted"><?php echo Q_Html::text($t['PrivateAfterAccept']) ?></p>
	<?php endif ?>
	<?php if ($role): ?>
		<div class="Sharing_engagement_chat">
			<?php echo Q::tool('Streams/chat', array(
				'publisherId' => $e['publisherId'], 'streamName' => $e['name']
			), array('id' => 'engagement_chat')) ?>
		</div>
	<?php endif ?>
</div>
