/**
 * Yii form widget.
 *
 * This is the JavaScript widget used by the yii\widgets\ActiveForm widget.
 *
 * @link http://www.yiiframework.com/
 * @copyright Copyright (c) 2008 Yii Software LLC
 * @license http://www.yiiframework.com/license/
 * @author Qiang Xue <qiang.xue@gmail.com>
 * @since 2.0
 */
(function ($) {

    $(document).bind("pjax:success", function(){
        $('.en-active-form').each(function() {
            var clientOptions = [];
            $(this).find('.form-control').each(function (index) {
                clientOptions[index] = $(this).data('arguments');
            });

            $(this).yiiActiveForm(clientOptions, []);
            console.log('rebuild -----> enhanced Yii Active Form');
        });
    });






    // $.fn.enYiiActiveForm = function (method) {
    //     if (methods[method]) {
    //         return methods[method].apply(this, Array.prototype.slice.call(arguments, 1));
    //     } else if (typeof method === 'object' || !method) {
    //         return methods.init.apply(arguments);
    //     } else {
    //         $.error('Method ' + method + ' does not exist on jQuery.enYiiActiveForm');
    //         return false;
    //     }
    // };


    // var methods = {
    //     init: function (selector__form, selector__form_control) {
    //         $(selector__form).each(function() {
    //             var clientOptions = [];
    //             $(this).find(selector__form_control).each(function (index) {
    //                 clientOptions[index] = $(this).data('options');
    //             });

    //             $(this).yiiActiveForm(clientOptions, []);
    //             console.log('11111');
    //         });
    //     },
    // };




})(window.jQuery);
