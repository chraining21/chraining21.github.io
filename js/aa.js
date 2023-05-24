$(function () {
  $.getJSON("/data/aa.json", function (data) {
    $.each(data, function (k, v) {
      let tr = "<tr>";
      tr += "<td>" + v.公佈日期 + "</td>";
      tr += "<td>" + v.案號 + "</td>";
      tr += "<td>" + v.地點名稱 + "</td>";
      tr += "<td>" + v.地址 + "</td>";
      tr += "</tr>";
      $("#tbody").append(tr);
    })
  });
})
var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
var tooltipList = tooltipTriggerList.map(function (tooltipTriggerEl) {
  return new bootstrap.Tooltip(tooltipTriggerEl)
});
var popoverTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="popover"]'));
var popoverList = popoverTriggerList.map(function (popoverTriggerEl) {
  return new bootstrap.Popover(popoverTriggerEl)
});