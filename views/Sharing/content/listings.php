<?php
/**
 * Listing page body. Variables: $gates, $loggedIn, $direction (null|offer|need),
 * $listings (array of Sharing_Listing::export).
 */
$text = Q_Text::get('Sharing/content');
$t = $text['listings'];
$canCreate = $gates['canCreateOffer'] || $gates['canCreateNeed'];
$canRespond = $gates['canRespondToOffer'] || $gates['canRespondToNeed'];
$tabs = array(null => $t['All'], 'offer' => $t['Offers'], 'need' => $t['Needs']);
?>
<div class="Sharing_listings" data-direction="<?php echo Q_Html::text((string)$direction) ?>">
	<div class="Sharing_listings_header">
		<h1><?php echo Q_Html::text($t['Title']) ?></h1>
		<p class="Sharing_listings_intro"><?php echo Q_Html::text($t['Intro']) ?></p>
		<?php if ($canCreate): ?>
			<button class="Q_button Sharing_post_button"
				data-canCreateOffer="<?php echo $gates['canCreateOffer'] ? 1 : 0 ?>"
				data-canCreateNeed="<?php echo $gates['canCreateNeed'] ? 1 : 0 ?>">
				<?php echo Q_Html::text($t['Post']) ?>
			</button>
		<?php endif ?>
	</div>
	<nav class="Sharing_direction_toggle">
		<?php foreach ($tabs as $key => $label): ?>
			<a class="Sharing_direction_tab<?php echo ($direction === $key || ($key === null && $direction === null)) ? ' Sharing_selected' : '' ?>"
			   href="<?php echo Q_Html::text(Sharing::url('sharing') . ($key ? "?direction=$key" : '')) ?>"
			   data-direction="<?php echo $key ?>"><?php echo Q_Html::text($label) ?></a>
		<?php endforeach ?>
	</nav>
	<?php if (empty($listings)): ?>
		<div class="Sharing_listings_list Sharing_empty">
			<?php echo Q_Html::text($direction ? $t['EmptyDirection'][$direction] : $t['Empty']) ?>
		</div>
	<?php else: ?>
		<div class="Sharing_listings_list">
			<?php foreach ($listings as $listing) {
				echo Q::view('Sharing/listing/card.php', @compact('listing', 'text'));
			} ?>
		</div>
	<?php endif ?>
	<?php if (!$loggedIn): ?>
		<p class="Sharing_listings_note"><?php echo Q_Html::text($t['LogInToRespond']) ?></p>
	<?php elseif (!$canRespond): ?>
		<p class="Sharing_listings_note"><?php echo Q_Html::text($t['CannotRespond']) ?></p>
	<?php endif ?>
</div>
