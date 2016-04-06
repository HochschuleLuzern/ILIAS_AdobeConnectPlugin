$(window).on("load.test", function () {
	$("select.form-control").on("change", function () {
        	$($(this)).parent().parent().find(":checkbox").prop("checked", true)
        }
)});

