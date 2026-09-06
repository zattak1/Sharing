<?php
/**
 * "Mine". Variables: $responded, $received (arrays of exports), $user.
 */
$text = Q_Text::get('Sharing/content');
$t = $text['engagements'];
?>
<div class="Sharing_engagements">
	<a class="Sharing_back" href="<?php echo Q_Html::text(Sharing::url('sharing')) ?>">&larr; <?php echo Q_Html::text($text['listing']['Back']) ?></a>
	<h1><?php echo Q_Html::text($t['Title']) ?></h1>
	<h2><?php echo Q_Html::text($t['Responded']) ?></h2>
	<?php if (empty($responded)): ?>
		<p class="Sharing_muted"><?php echo Q_Html::text($t['RespondedEmpty']) ?></p>
	<?php else: foreach ($responded as $engagement) {
		echo Q::view('Sharing/engagement/row.php', array(
			'engagement' => $engagement, 'allowed' => array(), 'text' => $text, 'showListing' => true
		));
	} endif ?>
	<h2><?php echo Q_Html::text($t['Received']) ?></h2>
	<?php if (empty($received)): ?>
		<p class="Sharing_muted"><?php echo Q_Html::text($t['ReceivedEmpty']) ?></p>
	<?php else: foreach ($received as $engagement) {
		echo Q::view('Sharing/engagement/row.php', array(
			'engagement' => $engagement, 'allowed' => array(), 'text' => $text,
			'showListing' => true, 'showResponder' => true
		));
	} endif ?>
</div>
