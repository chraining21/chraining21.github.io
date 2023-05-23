$(function(){
    $.getJSON("../data/aa.json",function(data){
        $.each(data, function(k,v){
            let tr = "<tr>";
            tr+="<td>"+v.公佈日期+"</td>";
            tr+="<td>"+v.案號+"</td>";
            tr+="<td>"+v.地點名稱+"</td>";
            tr+="<td>"+v.地址+"</td>";
            tr+="</tr>";
            $("#tbody").append(tr);
        })
    });
})