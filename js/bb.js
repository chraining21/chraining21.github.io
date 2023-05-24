$("#comment").click(function (e) {
  if ($("#resultArea").length) {
    return false;
  }
  let result = "<div class='mt-3' id='resultArea'><p>";
  result += "Your Email: ";
  result += $("#inputEmail").val() + "<br>";
  result += "Your Name: ";
  result += $("#inputName").val() + "<br>";
  result += "Your Comment: ";
  result += $("#inputComment").val() + "<br>";
  result += "</p></div>";
  $("#commentArea").append(result);
});
var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
var tooltipList = tooltipTriggerList.map(function (tooltipTriggerEl) {
  return new bootstrap.Tooltip(tooltipTriggerEl);
});
var popoverTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="popover"]'));
var popoverList = popoverTriggerList.map(function (popoverTriggerEl) {
  return new bootstrap.Popover(popoverTriggerEl);
});