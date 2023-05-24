let array = ["b", "c", "d", "e", "f", "g", "h", "i"];
$(function () {
  $.each(array, function (indexInArray, valueOfElement) {
    let pic = "<div class='col mb-2'>";
    pic += "<img src='/images/" + valueOfElement + ".jpeg'class='img-fluid rounded' alt='...'>";
    pic += "</div>";
    $("#pics").append(pic);
  });
})
$("#leftpic").click(function (e) {
  $("#pic").attr("src", "/images/b.jpeg");
});
$("#rightpic").click(function (e) {
  console.log("right");
  $("#pic").attr("src", "/images/c.jpeg");
});
var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
var tooltipList = tooltipTriggerList.map(function (tooltipTriggerEl) {
  return new bootstrap.Tooltip(tooltipTriggerEl);
});
var popoverTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="popover"]'));
var popoverList = popoverTriggerList.map(function (popoverTriggerEl) {
  return new bootstrap.Popover(popoverTriggerEl);
});