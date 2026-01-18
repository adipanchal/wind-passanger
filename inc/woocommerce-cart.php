<?php
// ===========================
// Continue shoping button cart page
// ===========================
add_action('woocommerce_cart_actions', function () {
    ?>
    <a class="button wc-backward continue-shopping"
        href="<?php echo esc_url(apply_filters('woocommerce_return_to_shop_redirect', wc_get_page_permalink('shop'))); ?>">
        <?php _e('Continuar A Comprar', 'woocommerce') ?> </a>
    <?php
});
