<?php
/**
 * Listing page body. Variables: $gates (the four booleans), $loggedIn.
 */
$text = Q_Text::get('Sharing/content');
$t = $text['listings'];
$canCreate = $gates['canCreateOffer'] || $gates['canCreateNeed'];
$canRespond = $gates['canRespondToOffer'] || $gates['canRespondToNeed'];
?>
<div class="Sharing_listings">
	<div class="Sharing_listings_header">
		<h1><?php echo Q_Html::text($t['Title']) ?></h1>
		<p class="Sharing_listings_intro"><?php echo Q_Html::text($t['Intro']) ?></p>
		<?php if ($canCreate): ?>
			<button class="Q_button Sharing_post_button" disabled
				title="<?php echo Q_Html::text($t['PostSoon']) ?>">
				<?php echo Q_Html::text($t['Post']) ?>
			</button>
		<?php endif ?>
	</div>
	<div class="Sharing_listings_list Sharing_empty">
		<?php echo Q_Html::text($t['Empty']) ?>
	</div>
	<?php if (!$loggedIn): ?>
		<p class="Sharing_listings_note"><?php echo Q_Html::text($t['LogInToRespond']) ?></p>
	<?php elseif (!$canRespond): ?>
		<p class="Sharing_listings_note"><?php echo Q_Html::text($t['CannotRespond']) ?></p>
	<?php endif ?>
</div>
