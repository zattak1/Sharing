<?php
/**
 * One listing. Variables: $listing, $isPublisher, $privateInstructions,
 * $canRespond, $user, $myEngagement, $myAllowed, $engagements, $openToResponses.
 */
$text = Q_Text::get('Sharing/content');
$t = $text['listing'];
$direction = $listing['direction'];
$kind = $listing['kind'];
?>
<div class="Sharing_listing Sharing_direction_<?php echo $direction ?> Sharing_kind_<?php echo $kind ?>"
     data-publisherId="<?php echo Q_Html::text($listing['publisherId']) ?>"
     data-streamName="<?php echo Q_Html::text($listing['name']) ?>">
	<a class="Sharing_back" href="<?php echo Q_Html::text(Sharing::url('sharing')) ?>">&larr; <?php echo Q_Html::text($t['Back']) ?></a>
	<div class="Sharing_listing_badges">
		<span class="Sharing_badge Sharing_badge_direction"><?php echo Q_Html::text($t['direction'][$direction]) ?></span>
		<span class="Sharing_badge Sharing_badge_kind"><?php echo Q_Html::text($t['kind'][$kind]) ?></span>
		<?php if ($listing['paused']): ?><span class="Sharing_badge Sharing_badge_paused"><?php echo Q_Html::text($t['Paused']) ?></span><?php endif ?>
	</div>
	<h1 class="Sharing_listing_title"><?php echo Q_Html::text($listing['title']) ?></h1>
	<div class="Sharing_listing_publisher">
		<?php echo Q::tool('Users/avatar', array('userId' => $listing['publisherId'], 'icon' => 50), array('id' => 'listing_publisher')) ?>
	</div>
	<?php if ($listing['content'] !== ''): ?>
		<div class="Sharing_listing_content"><?php echo nl2br(Q_Html::text($listing['content'])) ?></div>
	<?php endif ?>
	<?php if ($listing['area'] !== ''): ?>
		<div class="Sharing_listing_area"><strong><?php echo Q_Html::text($t['Area']) ?>:</strong> <?php echo Q_Html::text($listing['area']) ?></div>
	<?php endif ?>
	<div class="Sharing_listing_facts">
		<?php if ($listing['exclusive']): ?><span><?php echo Q_Html::text($t['Exclusive']) ?></span><?php endif ?>
		<?php if ($listing['custody']): ?><span><?php echo Q_Html::text($t['Custody']) ?></span><?php endif ?>
	</div>
	<?php if ($isPublisher): ?>
		<div class="Sharing_listing_private">
			<h3><?php echo Q_Html::text($t['PrivateHeading']) ?></h3>
			<?php if ($privateInstructions): ?>
				<p><?php echo nl2br(Q_Html::text($privateInstructions)) ?></p>
			<?php else: ?>
				<p class="Sharing_muted"><?php echo Q_Html::text($t['PrivateNone']) ?></p>
			<?php endif ?>
		</div>
		<div class="Sharing_listing_engagements">
			<h3><?php echo Q_Html::text($t['Responses']) ?></h3>
			<?php if (empty($engagements)): ?>
				<p class="Sharing_muted"><?php echo Q_Html::text($t['ResponsesNone']) ?></p>
			<?php else: foreach ($engagements as $row) {
				echo Q::view('Sharing/engagement/row.php', array(
					'engagement' => $row['engagement'], 'allowed' => $row['allowed'],
					'text' => $text, 'showResponder' => true
				));
			} endif ?>
			<div class="Sharing_transition_error" hidden></div>
		</div>
	<?php elseif ($myEngagement): ?>
		<div class="Sharing_listing_mine">
			<h3><?php echo Q_Html::text($t['YourResponse']) ?></h3>
			<?php echo Q::view('Sharing/engagement/row.php', array(
				'engagement' => $myEngagement, 'allowed' => $myAllowed, 'text' => $text
			)) ?>
			<div class="Sharing_transition_error" hidden></div>
		</div>
	<?php elseif ($openToResponses): ?>
		<form class="Sharing_respond_form">
			<h3><?php echo Q_Html::text($t['respond'][$direction]) ?></h3>
			<label><?php echo Q_Html::text($t['Note']) ?>
				<textarea name="note" rows="3" placeholder="<?php echo Q_Html::text($t['NotePlaceholder'][$direction]) ?>"></textarea></label>
			<?php if (!$listing['exclusive']): ?>
				<label><?php echo Q_Html::text($t['Quantity']) ?> <input type="number" name="quantity" value="1" min="1" max="20"></label>
			<?php endif ?>
			<div class="Sharing_respond_error" hidden></div>
			<button type="submit" class="Q_button Sharing_respond_button"><?php echo Q_Html::text($t['respond'][$direction]) ?></button>
		</form>
	<?php elseif ($canRespond && $listing['paused']): ?>
		<p class="Sharing_muted"><?php echo Q_Html::text($t['PausedNote']) ?></p>
	<?php endif ?>
