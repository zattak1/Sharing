<?php
/**
 * Listing page body. Variables: $canOffer, $canRequest, $loggedIn.
 */
$text = Q_Text::get('Sharing/content');
$t = $text['resources'];
?>
<div class="Sharing_resources">
	<div class="Sharing_resources_header">
		<h1><?php echo Q_Html::text($t['Title']) ?></h1>
		<p class="Sharing_resources_intro"><?php echo Q_Html::text($t['Intro']) ?></p>
		<?php if ($canOffer): ?>
			<button class="Q_button Sharing_offer_button" disabled
				title="<?php echo Q_Html::text($t['OfferSoon']) ?>">
				<?php echo Q_Html::text($t['Offer']) ?>
			</button>
		<?php endif ?>
	</div>
	<div class="Sharing_resources_list Sharing_empty">
		<?php echo Q_Html::text($t['Empty']) ?>
	</div>
	<?php if (!$loggedIn): ?>
		<p class="Sharing_resources_note"><?php echo Q_Html::text($t['LogInToRequest']) ?></p>
	<?php elseif (!$canRequest): ?>
		<p class="Sharing_resources_note"><?php echo Q_Html::text($t['CannotRequest']) ?></p>
	<?php endif ?>
</div>
