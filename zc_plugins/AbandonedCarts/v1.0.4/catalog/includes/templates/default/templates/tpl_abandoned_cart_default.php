<?php
/**
 * Abandoned Carts -- the unsubscribe page.
 *
 * Built from the classes every stock template styles (centerColumn, buttonRow,
 * cssButton), so it needs no template edits; copy it into your template's
 * templates/ folder to change it.
 *
 * @package  AbandonedCarts
 * @license  https://www.gnu.org/licenses/gpl-3.0.html GNU General Public License v3.0
 */
?>
<div class="centerColumn" id="abandonedCartDefault">
    <h1 id="abandonedCartHeading"><?= ABANDONED_CARTS_UNSUB_HEADING; ?></h1>
<?php if ($acView === 'invalid') { ?>
    <p><?= ABANDONED_CARTS_UNSUB_INVALID; ?></p>
<?php } elseif ($acView === 'done') { ?>
    <p><?= sprintf(ABANDONED_CARTS_UNSUB_DONE, zen_output_string_protected($acEmailShown)); ?></p>
<?php } else { ?>
    <p><?= sprintf(ABANDONED_CARTS_UNSUB_CONFIRM, zen_output_string_protected($acEmailShown)); ?></p>
    <?= zen_draw_form('abandoned_cart_unsubscribe', $acFormAction, 'post'); ?>
        <?= zen_draw_hidden_field('ac_confirm', '1'); ?>
        <div class="buttonRow forward"><?= zen_image_submit(BUTTON_IMAGE_SUBMIT, ABANDONED_CARTS_UNSUB_BUTTON); ?></div>
    </form>
<?php } ?>
</div>
