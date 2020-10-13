function closeSVGWindow(targetWindow) {
    targetWindow.document.write('<h3>SVG file printed.  Please close this window.</h3>');
    targetWindow.close();
}

function svgembed_printContent(path) {
    // Open window and load content
    var svgembed_print = window.open('', '_printwindow', 'location=no,height=400,width=600,scrollbars=yes,status=no');
    svgembed_print.document.write('<html><head></head><body><img src="' + decodeURIComponent(path) + '" ' +
                                  'style="width:100%;height:100%"></body></html>');
    svgembed_print.document.close();

    // Print
    setTimeout(function(){ svgembed_print.window.print(); }, 1000);
    setTimeout(function(){ closeSVGWindow(svgembed_print); }, 2000);
}

function svgembed_onMouseOver(object_id) {
    document.getElementById(object_id).className = document.getElementById(object_id).className + ' svgembed_print_border';
    return false;
}

function svgembed_onMouseOut(object_id) {
    document.getElementById(object_id).className = document.getElementById(object_id).className.replace(' svgembed_print_border', '');
    return false;
}
