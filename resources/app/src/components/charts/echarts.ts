// Modular ECharts: only the chart types and components the app uses. Imported
// lazily (its own chunk), so the shell paints before the chart library loads.
import * as echarts from 'echarts/core';
import { LineChart, BarChart, HeatmapChart, GraphChart } from 'echarts/charts';
import { GridComponent, TooltipComponent, MarkLineComponent, VisualMapComponent, BrushComponent, LegendComponent, ToolboxComponent } from 'echarts/components';
import { CanvasRenderer } from 'echarts/renderers';

echarts.use([LineChart, BarChart, HeatmapChart, GraphChart, GridComponent, TooltipComponent, MarkLineComponent, VisualMapComponent, BrushComponent, LegendComponent, ToolboxComponent, CanvasRenderer]);

export default echarts;
