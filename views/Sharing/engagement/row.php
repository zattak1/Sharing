<?php
/**
 * One engagement row. Variables: $engagement (export), $allowed (verb => state),
 * $text, $showListing (bool), $showResponder (bool).
 */
$t = $text['engagement'];
$e = $engagement;
?>
<div class="Sharing_engagement_row Sharing_state_<?php echo $e['state'] ?>"
     data-publisherId="<?php echo Q_Html::text($e['publisherId']) ?>"
     data-engagementId="<?php echo Q_Html::text($e['id']) ?>">
	<div class="Sharing_engagement_row_main">
		<?php if (!empty($showResponder)): ?>
			<span class="Sharing_engagement_responder"><?php echo Q::tool('Users/avatar', array(
				'userId' => $e['publisherId'], 'icon' => 40, 'short' => true
			), array('id' => 'eng_' . md5($e['name']))) ?></span>
		<?php endif ?>
		<a class="Sharing_engagement_link" href="<?php echo Q_Html::text($e['url']) ?>">
			<?php if (!empty($showListing)): ?>
				<span class="Sharing_engagement_listing_title"><?php echo Q_Html::text($e['title']) ?></span>
			<?php endif ?>
			<span class="Sharing_state_chip"><?php echo Q_Html::text($t['state'][$e['state']]) ?></span>
		</a>
		<?php if ($e['note'] !== ''): ?>
			<span class="Sharing_engagement_note"><?php echo Q_Html::text($e['note']) ?></span>
		<?php endif ?>
	</div>
	<?php if (!empty($allowed)): ?>
		<div class="Sharing_engagement_actions">
			<?php foreach ($allowed as $verb => $to): ?>
				<button class="Q_button Sharing_transition_button Sharing_verb_<?php echo $verb ?>"
					data-transition="<?php echo $verb ?>"><?php echo Q_Html::text($t['verb'][$verb]) ?></button>
			<?php endforeach ?>
		</div>
	<?php endif ?>
</div>
